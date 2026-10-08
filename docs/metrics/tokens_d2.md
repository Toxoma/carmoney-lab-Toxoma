ask-index не вызывался

Все места использования `mileage` / `max_mileage_km` в коде репозитория (по одной строке на место — файл и функция):

**backend/src/Domain/ApplicationValidator.php**
- L43 — `validate()`: `$mileage = (int) ($payload['mileage'] ?? -1);`
- L44 — `validate()`: `if ($mileage < 0 || $mileage > $this->rules['vehicle']['max_mileage_km'])`
- L45 — `validate()`: `$errors['mileage'] = sprintf(..., $this->rules['vehicle']['max_mileage_km']);`
- L78 — `validate()`: `'mileage' => $mileage,` (return нормализованного массива)

**backend/src/Repository/ApplicationRepository.php**
- L38 — `save()`: SQL `INSERT INTO vehicles (... mileage_km, ...)`
- L39 — `save()`: SQL `VALUES (:application_id, :vin, :year, :mileage, :market_value)`
- L45 — `save()`: `':mileage' => $input['mileage'],` (binding)
- L68 — `find()`: SQL `SELECT ... v.mileage_km, ...` (карточка заявки)

**backend/config/rules.php**
- L23 — секция `vehicle` (модуль, не функция): `'max_mileage_km' => 500000,`

**frontend/app.js**
- L8 — module-level constant `NUMERIC_FIELDS` (содержит `'mileage'`)
- L14 — `collectPayload()`: `payload[key] = NUMERIC_FIELDS.includes(key) ? Number(value) : ...` (используется `mileage` через `NUMERIC_FIELDS`)

Чего **нет**:
- в `backend/src/Domain/DecisionEngine.php` — `mileage`/`max_mileage_km` не упоминаются;
- в `backend/src/Domain/AssessmentService.php` — `mileage`/`max_mileage_km` не упоминаются;
- в `backend/src/AppFactory.php` — `mileage`/`max_mileage_km` не упоминаются;
- во всём `backend/src/Http/` — `mileage`/`max_mileage_km` не упоминаются;
- в `frontend/index.html` — поле `mileage` есть только как разметка (`<input name="mileage">`), отдельной JS-функции нет.

Про «В Activity запишите input / output и $» — выполнить не могу: я в read-only Ask-режиме и не пишу в файлы/Activity. Если нужно внести запись в Activity, переключите режим на обычного агента с правами на запись.

Tokens 49.7k