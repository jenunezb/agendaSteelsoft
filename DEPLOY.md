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

## 5. Nota importante

El archivo `api/config.php` contiene credenciales reales de base de datos. No lo publiques en un repositorio publico sin protegerlo antes.
