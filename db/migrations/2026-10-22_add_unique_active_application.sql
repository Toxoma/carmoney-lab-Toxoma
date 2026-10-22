-- Миграция 2026-10-22: запрет второй активной заявки клиента (ЗД. «одна активная заявка»).
-- Применяет человек вручную к существующей БД; в код не встраивается.
-- Новые инсталляции получают схему из db/schema.sql уже с этими изменениями.

-- ПРЕДПРОВЕРКА: убедитесь, что у клиентов нет больше одной активной заявки.
-- Если SELECT ниже вернёт строки — дубликаты нужно закрыть вручную ДО применения ALTER,
-- иначе ALTER TABLE упадёт с ошибкой дублета UNIQUE-ключа:
--
-- SELECT applicant_ref, COUNT(*) AS active_count
-- FROM applications
-- WHERE status IN ('new', 'decided')
-- GROUP BY applicant_ref
-- HAVING COUNT(*) > 1;

ALTER TABLE applications
    ADD COLUMN is_active TINYINT(1)
        GENERATED ALWAYS AS (IF(status IN ('new', 'decided'), 1, NULL)) STORED
        AFTER status,
    ADD UNIQUE KEY uq_applications_active (applicant_ref, is_active);