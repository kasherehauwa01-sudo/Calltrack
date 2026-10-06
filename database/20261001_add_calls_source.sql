-- Совместимость production-схемы с выборками, которые возвращают источник звонка.
-- Миграция не изменяет существующие строки и безопасна для старых Android-клиентов.
ALTER TABLE calls
    ADD COLUMN IF NOT EXISTS source VARCHAR(50) NULL AFTER user_phone;
