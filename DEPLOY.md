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
   - `whatsapp_access_token`
   - `whatsapp_phone_number_id`
   - `whatsapp_template_name`
   - `whatsapp_template_language` opcional, por defecto `es_CO`
   - `whatsapp_template_parameter_format` opcional, `named` o `positional`, por defecto `named`
   - `whatsapp_graph_version` opcional, por defecto `v23.0`
   - `whatsapp_cron_secret` recomendado para proteger la URL del cron

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

Si el template en Meta usa variables con nombre, debe aceptar 4 parametros con estos nombres:

1. `nombre_usuario`
2. `titulo_evento`
3. `fecha_hora_evento`
4. `tiempo_restante`

Si el template usa variables posicionales (`{{1}}` a `{{4}}`), configura `whatsapp_template_parameter_format = positional` y respeta ese mismo orden.

## 6. Nota importante

El archivo `api/config.php` contiene credenciales reales de base de datos. No lo publiques en un repositorio publico sin protegerlo antes.
