# AgendaSteelsoft

This project was generated using [Angular CLI](https://github.com/angular/angular-cli) version 19.2.19.

## Development server

To start a local development server, run:

```bash
ng serve
```

Once the server is running, open your browser and navigate to `http://localhost:4200/`. The application will automatically reload whenever you modify any of the source files.

## Code scaffolding

Angular CLI includes powerful code scaffolding tools. To generate a new component, run:

```bash
ng generate component component-name
```

For a complete list of available schematics (such as `components`, `directives`, or `pipes`), run:

```bash
ng generate --help
```

## Building

To build the project run:

```bash
ng build
```

This will compile your project and store the build artifacts in the `dist/` directory. By default, the production build optimizes your application for performance and speed.

## Personal agenda and login

The app now uses PHP sessions so each user sees only their own agenda, pending items, and financial entries.

- Any visitor can create a user account from the registration screen.
- Existing users can log in with their username and password.
- Each user can enable a public profile URL like `/julian`.
- Only activities marked as public are exposed on the public profile.
- Pending items and financial entries remain private.
- Existing records that already match the logged-in user's name in `assignee` are automatically linked to that user on login.
- The API creates the `users` table and the `user_id` columns automatically when it connects to MySQL.

## WhatsApp reminders

Activities can now store an optional reminder per event. Each user can register their WhatsApp number in the sidebar and enable notifications. Available lead times are:

- 1 hour before
- 30 minutes before
- 15 minutes before
- 5 minutes before

The backend includes [api/send-whatsapp-reminders.php](/c:/Users/Julian/Documents/Agenda%20Steelsoft/api/send-whatsapp-reminders.php), which is designed to be executed by cron every minute.

Required WhatsApp Cloud API settings:

- `WHATSAPP_PROVIDER` optional, use `meta` or `360dialog`, default `meta`
- `WHATSAPP_ACCESS_TOKEN`
- `WHATSAPP_PHONE_NUMBER_ID`
- `WHATSAPP_TEMPLATE_NAME`
- `WHATSAPP_TEMPLATE_LANGUAGE` optional, default `es_CO`
- `WHATSAPP_TEMPLATE_PARAMETER_FORMAT` optional, use `named` or `positional`, default `named`
- `WHATSAPP_GRAPH_VERSION` optional, default `v23.0`
- `WHATSAPP_CRON_SECRET` recommended if you trigger the script by URL
- `WHATSAPP_WEBHOOK_VERIFY_TOKEN` required for Meta to verify the webhook endpoint
- `WHATSAPP_360DIALOG_API_KEY` required when `WHATSAPP_PROVIDER=360dialog`
- `WHATSAPP_360DIALOG_BASE_URL` optional, default `https://waba-v2.360dialog.io`

Important: the script sends a WhatsApp template message, so the template must already exist and be approved in Meta. Do not leave `hello_world` configured for production reminders. The implementation supports two body parameter formats:

- `named`: `nombre_usuario`, `titulo_evento`, `fecha_hora_evento`, `tiempo_restante`
- `positional`: the same four values in that order, for templates using `{{1}}`, `{{2}}`, `{{3}}`, `{{4}}`

Helpful test modes:

- `?key=TU_SECRETO&dry_run=1` returns the payload preview without sending
- `?key=TU_SECRETO&test_number=573001234567` sends a direct test to a specific number
- `?key=TU_SECRETO&activity_id=123&force=1` sends a manual test for a specific activity

Webhook setup:

- Point Meta's callback URL to [api/whatsapp-webhook.php](/c:/Users/Julian/Documents/Agenda%20Steelsoft/api/whatsapp-webhook.php)
- Use the same value in Meta's verify token field and `WHATSAPP_WEBHOOK_VERIFY_TOKEN`
- Incoming `POST` events are logged to `api/logs/whatsapp-webhook.log` during setup

360dialog notes:

- The Messaging API base URL is `https://waba-v2.360dialog.io`
- Send the API key in the `D360-API-KEY` header
- The current reminder payload remains compatible because 360dialog accepts WhatsApp template payloads on `POST /messages`

## Telegram reminders

The backend also supports free reminders through Telegram bots. Each user can save a Telegram `chat_id` in the sidebar and enable notifications for their events.

Required Telegram settings:

- `TELEGRAM_BOT_TOKEN`
- `TELEGRAM_BOT_USERNAME` optional, useful for onboarding instructions
- `TELEGRAM_CRON_SECRET` recommended if you trigger the script by URL

Helpful test modes:

- `api/send-telegram-reminders.php?key=TU_SECRETO&dry_run=1` previews the outgoing payload
- `api/send-telegram-reminders.php?key=TU_SECRETO&test_chat_id=123456789` sends a direct test to a Telegram chat
- `api/send-telegram-reminders.php?key=TU_SECRETO&activity_id=123&force=1` sends a manual test for a specific activity

Telegram Bot API references:

- `sendMessage`: https://core.telegram.org/bots/api/#sendmessage
- `getUpdates`: https://core.telegram.org/bots/api/#getupdates

## Running unit tests

To execute unit tests with the [Karma](https://karma-runner.github.io) test runner, use the following command:

```bash
ng test
```

## Running end-to-end tests

For end-to-end (e2e) testing, run:

```bash
ng e2e
```

Angular CLI does not come with an end-to-end testing framework by default. You can choose one that suits your needs.

## Additional Resources

For more information on using the Angular CLI, including detailed command references, visit the [Angular CLI Overview and Command Reference](https://angular.dev/tools/cli) page.
