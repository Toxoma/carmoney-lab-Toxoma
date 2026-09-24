Тесты в tests/Unit/:

VinValidatorTest.php — проверяет формат VIN: 17 символов, без I/O/Q, регистронезависимость, пустая строка.
LtvCalculatorTest.php — считает LTV в процентах и бросает исключение при нулевой стоимости или неположительной сумме.
DecisionEngineTest.php — решение по LTV: approve ≤60, review 60–85, reject выше 85 (включая границу 85.01).
AssessmentServiceTest.php — сценарная сборка: approve с лимитом = запрошенной сумме, review/reject с нулевым лимитом, корректный возраст авто.
ApplicationValidatorTest.php — валидация заявки: нормализация VIN в верхний регистр, отклонение года «из будущего», суммы ниже минимума, сбор всех ошибок разом.
Работаю в папке F:\lessonsAi\carmoney-lab-Toxoma\.kilo\worktrees\1-2-3-toxoma, ветка 1-2-3-toxoma. Файлы не менял.