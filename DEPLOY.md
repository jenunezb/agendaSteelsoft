# Despliegue en Hostinger

## 1. Crear tablas

1. Entra a `phpMyAdmin`.
2. Abre la base `u873298580_agenda`.
3. Importa el archivo [database/schema.sql](/c:/Users/Julian/Documents/Agenda%20Steelsoft/database/schema.sql).

Si la base ya existe y no quieres reinstalarla, agrega estas columnas:

```sql
ALTER TABLE general_pendings
ADD COLUMN pending_date DATE NULL;

UPDATE general_pendings
SET pending_date = CURRENT_DATE
WHERE pending_date IS NULL;

ALTER TABLE general_pendings
MODIFY COLUMN pending_date DATE NOT NULL;

ALTER TABLE financial_entries
ADD COLUMN entry_date DATE NULL;

UPDATE financial_entries
SET entry_date = CURRENT_DATE
WHERE entry_date IS NULL;

ALTER TABLE financial_entries
MODIFY COLUMN entry_date DATE NOT NULL;

ALTER TABLE users
ADD COLUMN whatsapp_number VARCHAR(20) NOT NULL DEFAULT '',
ADD COLUMN whatsapp_notifications_enabled TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE activities
ADD COLUMN reminder_minutes SMALLINT UNSIGNED NULL,
ADD COLUMN reminder_sent_at DATETIME NULL;
```

## 2. Subir el backend PHP

1. Sube la carpeta `api/` a la raiz publica del sitio, por ejemplo `public_html/api`.
2. Verifica que estos archivos existan:
   - `api/bootstrap.php`
   - `api/config.php`
   - `api/activities.php`
   - `api/pendings.php`
   - `api/financial-entries.php`
   - `api/auth.php`
   - `api/public-profile.php`
   - `api/send-whatsapp-reminders.php`

3. Configura estas variables en el hosting o agrégalas de forma segura al arreglo de `api/config.php`:
   - `whatsapp_provider` opcional, por defecto `twilio`
   - `whatsapp_cron_secret` recomendado para proteger la URL del cron
   - `twilio_account_sid`
   - `twilio_auth_token`
   - `twilio_whatsapp_from`
   - `twilio_content_sid` para recordatorios de agenda
   - `twilio_booking_admin_content_sid` para administracion
   - `twilio_booking_professional_content_sid` para profesional
   - `twilio_booking_customer_content_sid` para cliente

## 3. Subir el frontend Angular

1. Ejecuta:

```powershell
npm run build
```

2. Sube el contenido de `dist/agenda-steelsoft/browser/` a `public_html/`.

Si tu build deja los archivos directamente en `dist/agenda-steelsoft/`, sube el contenido de esa carpeta.

3. Configura rewrite SPA para que rutas como `https://agenda.steelsoft.com.co/julian` carguen `index.html`.

En Apache puedes usar un `.htaccess` similar a este en `public_html/`:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^ index.html [L]
```

## 4. Probar endpoints

Prueba en el navegador:

- `https://steelsoft.com.co/api/activities.php`
- `https://steelsoft.com.co/api/pendings.php`
- `https://steelsoft.com.co/api/financial-entries.php`
- `https://steelsoft.com.co/api/public-profile.php?username=julian`

Deben responder JSON.

Para probar el disparador de recordatorios por URL:

- `https://steelsoft.com.co/api/send-whatsapp-reminders.php?key=TU_SECRETO`

Para previsualizar el payload sin enviar:

- `https://steelsoft.com.co/api/send-whatsapp-reminders.php?key=TU_SECRETO&dry_run=1`

Para enviar una prueba directa a un numero:

- `https://steelsoft.com.co/api/send-whatsapp-reminders.php?key=TU_SECRETO&test_number=573001234567`

Para enviar una prueba manual de una actividad puntual:

- `https://steelsoft.com.co/api/send-whatsapp-reminders.php?key=TU_SECRETO&activity_id=123&force=1`

Para previsualizar las plantillas de reservas sin crear una cita:

- `https://steelsoft.com.co/api/send-whatsapp-booking-tests.php?key=TU_SECRETO&dry_run=1&recipient=all&test_number=573001234567`

Para enviar una prueba real de confirmacion al cliente:

- `https://steelsoft.com.co/api/send-whatsapp-booking-tests.php?key=TU_SECRETO&recipient=customer&test_number=573001234567`

## 5. Configurar el cron de WhatsApp

Ejecuta el script cada minuto. Dos opciones comunes:

1. Cron por URL:

```text
https://steelsoft.com.co/api/send-whatsapp-reminders.php?key=TU_SECRETO
```

2. Cron por PHP CLI:

```bash
php public_html/api/send-whatsapp-reminders.php
```

Si usas Twilio Sandbox, recuerda unir primero el numero destino al sandbox antes de probar envios reales.

## 6. Nota importante

El archivo `api/config.php` contiene credenciales reales de base de datos. No lo publiques en un repositorio publico sin protegerlo antes.

## 7. Configurar Telegram

1. Crea un bot en Telegram usando `@BotFather`.
2. Guarda en `api/config.php` o en variables de entorno:
   - `telegram_bot_token`
   - `telegram_bot_username` opcional
   - `telegram_cron_secret`
3. Sube `api/send-telegram-reminders.php`.
4. Haz que cada usuario le escriba primero al bot y luego pegue su `chat_id` en la agenda.

Para probar por URL:

- `https://agenda.steelsoft.com.co/api/send-telegram-reminders.php?key=TU_SECRETO&test_chat_id=123456789`

Para cron por URL:

- `https://agenda.steelsoft.com.co/api/send-telegram-reminders.php?key=TU_SECRETO`
