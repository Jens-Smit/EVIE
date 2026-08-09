# EVIE - CSS & Tailwind Setup

## Aktuelle Konfiguration

### Tailwind CSS
EVIE verwendet **Tailwind CSS** für das Frontend-Styling mit zwei Optionen:

1. **Tailwind CDN** (empfohlen für schnelles Setup)
   - Automatisch geladen über `https://cdn.tailwindcss.com`
   - Keine lokale Installation nötig
   - Funktioniert sofort Out-of-the-Box

2. **Lokale Tailwind-Installation** (für Entwicklung)
   - Installiert über npm
   - Kann lokal kompiliert werden
   - Besser für Entwicklung mit Hot-Reload

### Dateistruktur

```
assets/
├── scripts/
│   └── app.js          # Haupt-JavaScript für Chat-Funktionalität
├── styles/
│   ├── tailwind.css    # Tailwind-Quelldatei mit @tailwind-Direktiven
│   ├── app.css         # Legacy-CSS-Klassen für Backward-Kompatibilität
│   └── README.md       # Diese Datei
```

## Option 1: Tailwind CDN (empfohlen)

✅ **Funktioniert bereits!** Keine weiteren Schritte nötig.

Die `base.html.twig` lädt Tailwind automatisch von CDN:
```html
<script src="https://cdn.tailwindcss.com"></script>
```

**Vorteile:**
- Keine Build-Schritte nötig
- Immer aktuelle Version
- Einfache Einrichtung

## Option 2: Lokale Tailwind-Kompilierung

Falls du Tailwind lokal kompilieren möchtest:

### 1. Abhängigkeiten installieren

```bash
npm install
```

### 2. Tailwind konfigurieren

Erstelle oder aktualisiere `tailwind.config.js`:

```javascript
module.exports = {
  content: [
    "./templates/**/*.html.twig",
    "./assets/**/*.js",
  ],
  theme: {
    extend: {
      colors: {
        primary: '#4a6fa5',
        secondary: '#166088',
        accent: '#4fc3f7',
      }
    }
  },
  plugins: [],
}
```

### 3. PostCSS-Konfiguration

Erstelle oder aktualisiere `postcss.config.js`:

```javascript
module.exports = {
  plugins: {
    tailwindcss: {},
    autoprefixer: {},
  },
}
```

### 4. Build-Skript

Die `package.json` enthält bereits Build-Skripte:

```json
"scripts": {
  "build:css": "node build-tailwind.js",
  "watch:css": "node build-tailwind.js"
}
```

Erstelle `build-tailwind.js`:

```javascript
const postcss = require('postcss');
const tailwindcss = require('tailwindcss');
const autoprefixer = require('autoprefixer');
const fs = require('fs');

const input = fs.readFileSync('./assets/styles/tailwind.css', 'utf8');

postcss([
  tailwindcss(),
  autoprefixer(),
])
.process(input, { from: './assets/styles/tailwind.css', to: './public/assets/styles/tailwind-compiled.css' })
.then(result => {
  // Stelle sicher, dass das Verzeichnis existiert
  const outputDir = './public/assets/styles';
  if (!fs.existsSync(outputDir)) {
    fs.mkdirSync(outputDir, { recursive: true });
  }
  
  fs.writeFileSync('./public/assets/styles/tailwind-compiled.css', result.css);
  console.log('✅ Tailwind CSS erfolgreich kompiliert!');
})
.catch(error => {
  console.error('❌ Fehler beim Kompilieren von Tailwind CSS:', error);
});
```

### 5. CSS kompilieren

```bash
npm run build:css
```

Die kompilierte Datei wird unter `public/assets/styles/tailwind-compiled.css` gespeichert.

### 6. Tailwind CDN entfernen (optional)

Falls du nur die lokale Version verwenden möchtest, entferne aus `base.html.twig`:

```html
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = {...}
</script>
```

Und ändere den Link zu:
```html
<link href="{{ asset('build/assets/styles/tailwind-compiled.css') }}" rel="stylesheet">
```

## JavaScript

### app.js

Die Haupt-JavaScript-Datei (`assets/scripts/app.js`) bietet:

- **Chat-Funktionalität** für den AI-Dialog
- **AJAX-Anfragen** an den Agenten
- **Tool-Freigabe** (Approve/Reject)
- **Benachrichtigungen** für Systemmeldungen
- **Markdown-Rendering** für Agenten-Antworten
- **JSON-Formatierung** für strukturierte Antworten

### Verwendung

Die `app.js` wird automatisch in `base.html.twig` geladen:

```html
<script src="{{ asset('assets/scripts/app.js') }}"></script>
```

## Backward-Kompatibilität

### app.css

Die Datei `assets/styles/app.css` enthält Legacy-Klassen, die in bestehenden Templates verwendet werden:

- `.card`, `.card-title` - Karten-Layout
- `.table`, `.table th`, `.table td` - Tabellen-Styling
- `.btn`, `.btn-primary`, `.btn-secondary`, etc. - Button-Stile
- `.badge`, `.badge-approved`, `.badge-pending`, `.badge-rejected` - Status-Badges

Diese Klassen werden nach und nach durch Tailwind-Klassen ersetzt.

## Troubleshooting

### Tailwind funktioniert nicht

1. **Prüfe die Netzwerkverbindung** - Tailwind CDN benötigt Internet
2. **Cache leeren** - Browser-Cache und Symfony-Cache
   ```bash
   php bin/console cache:clear
   ```
3. **Konsolenfehler prüfen** - F12 → Console
4. **Lokale Kompilierung versuchen** - `npm run build:css`

### JavaScript funktioniert nicht

1. **Prüfe den Pfad** - `assets/scripts/app.js` muss existieren
2. **Symfony Assets installieren**
   ```bash
   php bin/console assets:install
   ```
3. **Konsolenfehler prüfen** - 404 Fehler für app.js?
4. **Manuell prüfen** - `http://localhost:8000/assets/scripts/app.js` aufrufen

### Tailwind-Klassen werden nicht angewendet

1. **Prüfe die HTML-Struktur** - Tailwind benötigt die richtigen Klassen
2. **Prüfe die Konfiguration** - `tailwind.config.js` muss die richtigen Pfade enthalten
3. **Kompilieren** - Falls lokal: `npm run build:css`

## Empfohlene Vorgehensweise

1. **Für schnelles Setup** → Tailwind CDN verwenden (funktioniert bereits)
2. **Für Entwicklung** → Lokale Tailwind-Installation einrichten
3. **Für Produktion** → Lokale Kompilierung mit `npm run build:css`

## Nächste Schritte

- [ ] Tailwind lokal einrichten (optional)
- [ ] Legacy-Klassen in Templates durch Tailwind-Klassen ersetzen
- [ ] `app.css` entfernen, sobald alle Templates Tailwind verwenden
