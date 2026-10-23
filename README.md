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
   **Den Schlüssel mit einem technischen Ruboja-Nutzer erzeugen** (z. B. „Website“):
   Ruboja begrüßt anonyme Besucher mit dem Vornamen des Schlüssel-Erstellers
   („Hallo Enrico, schön dich zu sehen!“), und ihre Chats laufen auf dessen Konto.
2. Plugin installieren (Zip aus dem neuesten GitHub-Release) und aktivieren.
3. **Einstellungen → Ruboja-Assistenten**: Schlüssel eintragen, speichern. Die Liste
   der Assistenten wird geladen.
4. Gewünschte Assistenten **freigeben** und speichern.
5. Block **„Ruboja-Assistent“** einfügen oder Shortcode verwenden.

Schlüssel per `wp-config.php` statt Datenbank:

```php
define( 'RUBOJA_ASSISTANTS_API_KEY', 'sk_live_…' );
```

Die API-Adresse ist fest `https://api.ruboja.de/api/v1` und erscheint nicht in den
Einstellungen. Nur für ein Ruboja-Testsystem lässt sie sich überschreiben (nur https):

```php
define( 'RUBOJA_ASSISTANTS_API_BASE', 'https://…/api/v1' );
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
| `color`    | Hex-Farbe                | Ruboja-Rot `#df252b`     |
| `position` | `right`, `left`          | `right`                  |

### Höhe und Kopfzeile

- Ruboja meldet seine Inhaltshöhe nicht an die einbettende Seite. Die Höhe eines
  Inline-Assistenten wird deshalb fest eingestellt (Block: „Höhe“, Shortcode: `height`).
  Für ein langes Startformular wie beim Vorauszahlungs-Assistenten passen etwa
  **1.050 px** bei normaler Inhaltsbreite; auf dem Handy wird das Formular länger
  (gemessen: ca. 1.225 px bei 420 px Breite) und scrollt dann im iFrame.
- **Ruboja-Kopfzeile ausblenden** (Standard an): Logo, Assistentenname und Organisation
  stehen in einer 60 px hohen Zeile, die sich in Ruboja nicht abschalten lässt. Das
  Plugin schiebt den iFrame um diese Höhe nach oben und schneidet sie ab. Ändert
  Ruboja sein Layout, die Option ausschalten oder per CSS anpassen:
  `.ruboja-assistant { --ruboja-header-offset: 60px; }`
- **Begrüßung abschneiden** (experimentell, Standard aus): Ruboja begrüßt mit einem
  zufälligen Satz samt Vorname („Schön, dass du da bist, Enrico!“), ohne Einstellung zum
  Abschalten. Die Option schneidet zusätzlich 98 px ab, aber nur ab 640 px Breite: Schmaler
  bricht die Begrüßung je nach Satz um, sie bleibt dann sichtbar statt halb abgeschnitten.
  Inline-Höhe dabei etwa 100 px niedriger wählen (Vorauszahlungs-Assistent: ca. 950 px) und
  lieber etwas zu knapp, sonst zentriert Ruboja den Inhalt und die Begrüßung rutscht ins Bild.
  Nach dem Start des Gesprächs bleiben die obersten ca. 100 px des Chats verdeckt.
  Feinjustierung per CSS: `.ruboja-assistant { --ruboja-greeting-offset: 98px; }`

## Datenschutz

- **Laden erst nach Klick** (Standard an): Bis zum Klick geht keine Verbindung zu Ruboja.
- **Verlauf für wiederkehrende Besucher** (Standard aus): setzt ein funktionales Cookie
  `ruboja_assistants_visitor` mit Zufalls-ID. Ruboja bekommt nur einen daraus abgeleiteten
  HMAC als `reference_id`. Ohne Verlauf bekommt jede Sitzung eine neue Zufallsreferenz.
- **Nutzer-Zuordnung** (Standard aus): schickt für angemeldete WordPress-Nutzer die
  E-Mail-Adresse als `X-User-Mail`. Gedacht für Intranets, deren Nutzer auch in Ruboja sind.
- Ohne Nutzer-Zuordnung laufen alle Chats anonym auf dem Konto des Schlüssel-Erstellers
  (Ruboja-Modus `operator`).

## Design

Die Oberfläche des Plugins folgt dem Ruboja-CD (gemessen auf ruboja.de, Oktober 2026):
Rot `#df252b` als Akzent, Schrift Outfit, flache Buttons mit 9 px Rundung, Karten weiß mit
1 px Rand `#e9ecef` und 18 px Rundung, Text `#1a1a1a` / `#2e2e2e`. Alle Werte stehen als
CSS-Variablen an `.ruboja-assistant` und lassen sich im Theme überschreiben, z. B.
`--ruboja-color`, `--ruboja-font`, `--ruboja-radius-button`, `--ruboja-radius-card`.
Outfit liegt im Plugin (`assets/fonts/`, Latin-Teilmenge aus `@fontsource-variable/outfit`
5.3.0, 32 KB, SIL Open Font License 1.1, siehe `assets/fonts/OFL.txt`). Sie wird unter dem
eigenen Namen „Ruboja Outfit“ vom eigenen Server geladen, nur wenn ein Assistent auf der
Seite steht, und überschreibt keine Schrift des Themes. Externe Schrift-Dienste werden nicht
aufgerufen.

## Wo der API-Schlüssel liegt

- **Nie in den Plugin-Dateien.** Der Schlüssel steht verschlüsselt in der Datenbank
  (`wp_options`, Option `ruboja_assistants_api_key`, Format `v1:…`) oder als Konstante in
  der `wp-config.php`. Wer den Plugin-Ordner herunterlädt (FTP, Dateimanager des Hosters,
  Download-Plugins) oder die Release-Zip weitergibt, gibt keinen Schlüssel weiter.
- **Löschen über Plugins → Löschen** entfernt Schlüssel, Einstellungen und Assistentenliste
  (`uninstall.php`, auch auf allen Seiten einer Multisite). **Deaktivieren** behält alles,
  damit nach dem Reaktivieren nichts neu einzurichten ist.
- **Ordner per FTP entfernen** löscht nichts in der Datenbank, denn WordPress führt
  `uninstall.php` dann nicht aus. Den Schlüssel vorher in den Einstellungen mit
  „Gespeicherten Schlüssel entfernen“ löschen oder in Ruboja widerrufen.
- **Komplette Website-Sicherungen** (Datenbank und `wp-config.php`, auch Staging-Kopien)
  enthalten den verschlüsselten Schlüssel und die Salts, mit denen er sich entschlüsseln
  lässt. Gerät so eine Sicherung in falsche Hände: Schlüssel in Ruboja unter
  Einstellungen → API-Schlüssel widerrufen und neu erzeugen.

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

Versionen folgen [SemVer](https://semver.org/). Ein Release in vier Schritten:

```bash
bin/bump-version.sh 0.2.0     # Version in allen Dateien setzen
# CHANGELOG.md: Abschnitt "## [0.2.0] - JJJJ-MM-TT" anlegen (wird zum Release-Text)
git commit -am "Release 0.2.0"
git tag v0.2.0 && git push origin main v0.2.0
```

Der Workflow `release.yml` prüft, dass Tag und Versionen übereinstimmen, nimmt den
CHANGELOG-Abschnitt als Release-Text, baut `ruboja-assistants.zip` und veröffentlicht
das GitHub-Release.

### Updates in WordPress

- Installierte Seiten zeigen die neue Version unter **Plugins** („Jetzt aktualisieren“)
  und unter **Dashboard → Aktualisierungen**. Automatische Updates lassen sich in der
  Plugin-Liste einschalten. Grundlage ist der Header `Update URI`
  (`https://github.com/EnricoMiebach/ruboja-assistants`), das Repository ist öffentlich.
- WordPress fragt etwa alle 12 Stunden nach, das Plugin merkt sich das Release
  6 Stunden. Sofort geht es mit **Dashboard → Aktualisierungen → Erneut prüfen**.
- „Details ansehen“ zeigt die Release-Notizen.
- Eine Kopie mit `.git`-Ordner (Entwicklung, wp-env) bekommt nie ein Update angeboten,
  damit WordPress kein Arbeitsverzeichnis überschreibt. Updates also immer an einer
  Installation aus der Release-Zip testen.
- Bei einem privaten Repository bräuchte jede Seite ein Token mit Lesezugriff:
  `define( 'RUBOJA_ASSISTANTS_GITHUB_TOKEN', 'github_pat_…' );` (nicht getestet).

## Lizenz

GPL-2.0-or-later, siehe [LICENSE](LICENSE).
