Все файлы домена прочитаны. Проверю, как они связываются (кто создаёт сервисы и передаёт rules.php), чтобы описать порядок вызовов без домыслов.
Проверю ещё, где в коде вообще упоминается пробег (mileage), чтобы точноответить, что уже проверяется.
Посмотрю ещё ApplicationController (как assess() вызывается и что происходит при ValidationException) и тесты — чтобы точно описать порядок и существующие проверки пробега.
Как считается решение approve / review / reject
Участники
Файл	Роль
backend/config/rules.php	Справочник бизнес-чисел: VIN, границы года/пробега/суммы/срока, пороги LTV. Код ничего не хардкодит — читает отсюда
backend/src/Domain/AssessmentService.php	Оркестратор: валидация → LTV → решение
backend/src/Domain/ApplicationValidator.php	Валидация заявки, нормализация полей, ошибки → ValidationException
backend/src/Domain/VinValidator.php	Формальная проверка VIN (длина, алфавит, запрещённые I/O/Q)
backend/src/Domain/VehicleAge.php	Возраст авто: currentYear − productionYear
backend/src/Domain/LtvCalculator.php	LTV = requested_amount / market_value × 100
backend/src/Domain/DecisionEngine.php	Единственное место, где рождается approve/review/reject
backend/src/Domain/ValidationException.php	Исключение с картой «поле → сообщение»
Сборка: AppFactory::create() (backend/src/AppFactory.php:27–38) делает require backend/config/rules.php и строит цепочку VinValidator($rules['vin']) → ApplicationValidator($rules, …) → LtvCalculator() → DecisionEngine($rules['ltv']) → AssessmentService(…).

Порядок вызовов

<<картинка>>

По шагам (AssessmentService::assess(), строки 30–41):

ApplicationValidator::validate($payload) — строки 24–83. Поле за полем: VIN нормализуется (strtoupper/trim) и проверяется через VinValidator::isValid(); год через VehicleAge::inYears() проверяется на min_year (1990), возраст < 0 (будущее) и max_age_years (20); пробег на 0..500 000; market_value > 0; сумма в amount.min..max; срок в term.min_months..max_months. Любая ошибка → ValidationException → контроллер отвечает 422, решение не вычисляется вообще.
LtvCalculator::calculate(requested_amount, market_value) — round(amount / value * 100, 2).
DecisionEngine::decide($ltv) — строки 30–41, все пороги из rules['ltv'] (approve_max = 60.0, review_max = 85.0):
$ltv < 60.0 → approve
$ltv <= 85.0 → review
иначе → reject
VehicleAge::inYears() вызывается повторно для поля vehicle_age в ответе, approved_limit = запрошенной сумме при approve, иначе 0. Контроллер сохраняет заявку (ApplicationRepository::save() → vehicles.mileage_km в БД) и отвечает 201.
Два фактических нюанса в коде:

Граница approve: в коде строгое $ltv < approveMax, то есть LTV ровно 60.0 даёт review, хотя докблоки в DecisionEngine.php (строки 10–12) и rules.php (строки 39–41) обещают LTV <= approve_max → approve.
ltv_by_age в rules.php заполнен, но нигде не используется — по комментарию это несделанная задача LOAN-12.
Куда встанет правило «пробег ≤ 400 000 км, иначе review»
Место по архитектуре — DecisionEngine::decide() (DecisionEngine.php:30–41): это единственная точка, где живут правила решения. Правило встанет как проверка пробега внутри decide() наряду с LTV-порогами (семантика «как минимум review»: если пробег > 400 000, то approve понижается до review; review/reject остаются). Альтернатива, где данные уже под рукой — AssessmentService::assess() между строкой 33 ($decision = $this->decisionEngine->decide($ltv)) и return, потому что там доступен $input['mileage']; но тогда правило живёт вне DecisionEngine, а сам AssessmentService конфиг не получает.

Что для правила уже есть:

Поле mileage в заявке: фронтенд отправляет, ApplicationValidator::validate() приводит к int и возвращает в нормализованном массиве (ApplicationValidator.php:43–46, 78).
$input['mileage'] доступен в AssessmentService::assess() и попадает в ответ (input) и в БД (ApplicationRepository::save(), vehicles.mileage_km).
Секция vehicle в rules.php, где рядом с существующим max_mileage_km мог бы лечь новый порог.
Константа DecisionEngine::REVIEW.
Чего не хватает:

Порога 400 000 в rules.php — нет; единственный ключ про пробег — max_mileage_km = 500000, и это граница валидации (422), а не решения.
Пробега в DecisionEngine — нет: конструктор читает только ltv-пороги, decide(float $ltv) принимает один аргумент. Понадобится передать порог (в конструктор, по аналогии с ltv) и пробег (расширить сигнатуру decide() + править вызов в AssessmentService::assess() строка 33).
Доступа к конфигу в AssessmentService — нет (он получает всё через коллабораторов; по конвенции числа не хардкодятся).
Механизма понижения решения (approve → review по второму условию) — нет, решение сейчас считается только из LTV.
Тестов на пробег — нет: в tests/ mileage встречается только как валидные значения 86000/96000… точнее 84000 и 96000 в фикстурах, без граничных проверок.
Важное взаимодействие с существующей валидацией: пробег > 500 000 сейчас отбрасывается ещё в ApplicationValidator::validate() с ошибкой 422 — до решения такие заявки не доходят. Значит новое правило реально сработает только в диапазоне 400 001–500 000 км.

Что уже сейчас проверяется про пробег
Ровно одна проверка — ApplicationValidator::validate(), строки 43–46: (int)($payload['mileage'] ?? -1), ошибка при значении < 0 или > 500000 (из rules['vehicle']['max_mileage_km']), сообщение «Пробег от 0 до 500000 км», уходит в ValidationException → HTTP 422. Отсутствующее поле превращается в −1 и тоже даёт ошибку.

Всё остальное — нет: в LtvCalculator пробег не участвует, в DecisionEngine не передаётся, в AssessmentService используется только для передачи в ответ и БД, граничных тестов на пробег нет. Сама фича «400 000 км» сегодня существует только в документации (docs/README.md, docs/plan/README.md с граничными значениями 399999/400000/400001, README.md как тема ДЗ) — в коде её нет.