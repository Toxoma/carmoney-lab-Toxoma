# Kilo Hello

готов

1. По README и корневым файлам: `carmoney-lab` — учебный сервис предварительной оценки заявки на заём под ПТС (PHP 8.3 + Slim, MySQL 8): принимает заявку (VIN, год, пробег, оценочная стоимость, сумма, срок), считает LTV и возвращает решение `approve` / `review` / `reject`.
2. Команды Makefile: `make up` (docker compose up -d --build, сервис на http://localhost:8080), `make down`, `make ps`, `make logs`, `make install`, `make test` (PHPUnit), `make lint` (php -l), `make seed`, `make help`; в docker-compose.yml отдельных команд запуска/проверки не нашёл — только сервисы `backend` (php -S 0.0.0.0:8080) и `db` (mysql:8.0 с healthcheck).
3. Решение approve / review / reject считается в `backend/src/Domain/` — класс `DecisionEngine.php` (пороги из `backend/config/rules.php`), вызывается из `AssessmentService.php`.

модель: training-2026-09-glm-5.3
