# Ruboja Assistants (WordPress-Plugin)

Bindet Ruboja-Assistenten auf einer WordPress-Seite ein, inline im Inhalt oder als
schwebendes Chatfenster. Der Assistent läuft genau so, wie er in Ruboja eingerichtet
ist: System-Prompt, Wissen, Werkzeuge, festes Modell und Startformular.

## So funktioniert es

```
Besucher klickt „Assistent starten“
  → Browser: POST /wp-json/ruboja-assistants/v1/sessions  { assistant }
  → WordPress prüft Freigabe, Login-Pflicht und Rate-Limit
  → WordPress: POST https://api.ruboja.de/api/v1/chat-sessions  (Bearer API-Schlüssel)
  → Ruboja liefert eine Einmal-URL (10 Minuten gültig, einmal einlösbar)
  → Browser zeigt die URL im iFrame, der Chat läuft direkt mit Ruboja
```

Der API-Schlüssel verlässt nie den Server. Er liegt verschlüsselt in der Datenbank
(libsodium, Schlüssel aus den Salts in `wp-config.php`) oder als Konstante in
`wp-config.php`.

## Einrichtung

1. In Ruboja unter **Einstellungen → API-Schlüssel** einen Schlüssel erzeugen.
   Nutzer-Zuordnung **„Optional“** wählen, sonst können anonyme Besucher nichts starten.
   Der Schlüssel hat die Rechte seines Erstellers, er sieht also nur dessen Assistenten.
2. Plugin installieren (Zip aus dem neuesten GitHub-Release) und aktivieren.
3. **Einstellungen → Ruboja-Assistenten**: Schlüssel eintragen, speichern. Die Liste
   der Assistenten wird geladen.
4. Gewünschte Assistenten **freigeben** und speichern.
5. Block **„Ruboja-Assistent“** einfügen oder Shortcode verwenden.

Schlüssel per `wp-config.php` statt Datenbank:

```php
define( 'RUBOJA_ASSISTANTS_API_KEY', 'sk_live_…' );
```

### Shortcode

```text
[ruboja_assistant id="6ad744a4c3e693b346da671a"]
[ruboja_assistant id="…" mode="floating" label="Fragen? Jetzt chatten" position="left"]
[ruboja_assistant id="…" height="800" title="Vorauszahlungen prüfen" color="#1f3a5f"]
```

| Attribut   | Werte                    | Standard                 |
|------------|--------------------------|--------------------------|
| `id`       | Assistenten-ID (Pflicht) |                          |
| `mode`     | `inline`, `floating`     | `inline`                 |
| `height`   | 320–2000 (Pixel, inline) | `720`                    |
| `title`    | Text                     | Titel aus Ruboja         |
| `label`    | Button-Text (floating)   | Titel                    |
| `color`    | Hex-Farbe                | Akzentfarbe der Settings |
| `position` | `right`, `left`          | `right`                  |

## Datenschutz

- **Laden erst nach Klick** (Standard an): Bis zum Klick geht keine Verbindung zu Ruboja.
- **Verlauf für wiederkehrende Besucher** (Standard aus): setzt ein funktionales Cookie
  `ruboja_assistants_visitor` mit Zufalls-ID. Ruboja bekommt nur einen daraus abgeleiteten
  HMAC als `reference_id`. Ohne Verlauf bekommt jede Sitzung eine neue Zufallsreferenz.
- **Nutzer-Zuordnung** (Standard aus): schickt für angemeldete WordPress-Nutzer die
  E-Mail-Adresse als `X-User-Mail`. Gedacht für Intranets, deren Nutzer auch in Ruboja sind.
- Ohne Nutzer-Zuordnung laufen alle Chats anonym auf dem Konto des Schlüssel-Erstellers
  (Ruboja-Modus `operator`).

## Entwicklung

Voraussetzungen: Docker, Node.js 20+.

```bash
npm install
npm run env:start        # WordPress auf http://localhost:8888 (admin / password)
npm run composer install # PHP-Werkzeuge im Container installieren
npm run lint:php         # WordPress Coding Standards
npm run test:php         # PHPUnit gegen die WordPress-Testsuite
npm run i18n:pot         # languages/ruboja-assistants.pot neu erzeugen
npm run i18n:compile     # .po → .mo und .json
npm run build:zip        # dist/ruboja-assistants.zip
```

Aufbau:

| Pfad                              | Inhalt                                          |
|-----------------------------------|-------------------------------------------------|
| `ruboja-assistants.php`           | Plugin-Header, Version, Bootstrap               |
| `includes/class-settings.php`     | Optionen, verschlüsselter Schlüssel, Freigaben  |
| `includes/class-api-client.php`   | Ruboja-API (`/assistants`, `/chat-sessions`)    |
| `includes/class-rest-controller.php` | Endpunkt für den Browser, Rate-Limit, Cookie |
| `includes/class-embed.php`        | Shortcode, Block-Rendering, seitenweiter Chat   |
| `includes/class-admin-page.php`   | Einstellungsseite                               |
| `includes/class-updater.php`      | Updates aus GitHub-Releases                     |
| `blocks/assistant/`               | Block (ohne Build-Schritt)                      |
| `assets/`                         | Front-End-JS/CSS, Admin-JS/CSS                  |
| `languages/`                      | Übersetzungen (Quelltexte Englisch, dazu Deutsch) |

## Versionierung und Releases

- Versionen nach [SemVer](https://semver.org/), Änderungen in `CHANGELOG.md`.
- Für ein Release die Version an **drei Stellen** anheben: Header `Version:` und
  `const VERSION` in `ruboja-assistants.php`, `Stable tag:` in `readme.txt`
  (dazu `package.json` und `blocks/assistant/block.json`).
- Commit, dann Tag pushen:

  ```bash
  git tag v0.2.0
  git push origin main --tags
  ```

- Der Workflow `release.yml` prüft, dass Tag und Versionen übereinstimmen, baut
  `ruboja-assistants.zip` und veröffentlicht das GitHub-Release.
- WordPress-Seiten mit installiertem Plugin zeigen das Update unter
  **Dashboard → Aktualisierungen** an. Grundlage ist der Header `Update URI`
  (`https://github.com/<owner>/<repo>`). Bei einem privaten Repository in
  `wp-config.php` ein Token mit Lesezugriff hinterlegen:

  ```php
  define( 'RUBOJA_ASSISTANTS_GITHUB_TOKEN', 'github_pat_…' );
  ```

## Lizenz

GPL-2.0-or-later, siehe [LICENSE](LICENSE).
