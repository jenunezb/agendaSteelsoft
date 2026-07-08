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

Required Twilio WhatsApp settings:

- `WHATSAPP_PROVIDER` optional, default `twilio`
- `WHATSAPP_CRON_SECRET` recommended if you trigger the script by URL
- `TWILIO_ACCOUNT_SID`
- `TWILIO_AUTH_TOKEN`
- `TWILIO_WHATSAPP_FROM`

The current implementation sends a plain WhatsApp text message through Twilio. If you use the Twilio Sandbox, the destination number must first join the sandbox.

Helpful test modes:

- `?key=TU_SECRETO&dry_run=1` returns the payload preview without sending
- `?key=TU_SECRETO&test_number=573001234567` sends a direct test to a specific number
- `?key=TU_SECRETO&activity_id=123&force=1` sends a manual test for a specific activity

## WhatsApp booking notifications

Public bookings can also notify three audiences through Twilio WhatsApp:

- `TWILIO_BOOKING_ADMIN_CONTENT_SID`
- `TWILIO_BOOKING_PROFESSIONAL_CONTENT_SID`
- `TWILIO_BOOKING_CUSTOMER_CONTENT_SID`

If one of those values is empty, the booking flow falls back to a free-form `Body` for that recipient. That can fail outside the 24-hour customer service window, so production setups should use approved templates for all three.

Template variable mapping used by the booking flow:

- `admin`: `1=service`, `2=date`, `3=time`, `4=customer`, `5=professional`, `6=customer phone`
- `professional`: `1=professional`, `2=service`, `3=date`, `4=time`, `5=customer`, `6=customer phone`
- `customer`: `1=service`, `2=date`, `3=time`, `4=professional`

The repo includes [run-whatsapp-booking-test.php](/c:/Users/Julian/Documents/Agenda%20Steelsoft/run-whatsapp-booking-test.php) and [api/send-whatsapp-booking-tests.php](/c:/Users/Julian/Documents/Agenda%20Steelsoft/api/send-whatsapp-booking-tests.php) to preview or send booking notifications without creating a real reservation.

Examples:

- `php run-whatsapp-booking-test.php --dry-run --recipient=all 573001234567`
- `php run-whatsapp-booking-test.php --send --recipient=customer --service-name=Consulta --customer-name=Laura 573001234567`
- `https://tu-dominio/api/send-whatsapp-booking-tests.php?key=TU_SECRETO&dry_run=1&recipient=all&test_number=573001234567`

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
