# Fix: Konfigurator 3D — iOS Safari crash & hang

## Objaw

Na iPhone (iOS < 16.4) konfigurator 3D wisi na loaderze. Model się nie wyświetla, formularz nie reaguje na zmiany wymiarów i materiałów.

## Przyczyna

Plik: `admin.kronosfera.erozrys.pl/assets/js_pages/corps/dist/corps.js` (klasa `Ru`, nazwa zobfuskowana)

`workerCreate()` tworzy `Promise` który **nigdy się nie rozwiązuje** gdy `OffscreenCanvas` nie istnieje. Na iOS < 16.4 `new OffscreenCanvas(1,1)` rzuca `ReferenceError: Can't find variable: OffscreenCanvas`. Promise zostaje odrzucony, `catch` w `init()` to łapie, ale `canvasCreate()` też rzuca w `checkOffscreenCanvasSupported()` — żadna ścieżka (`startWithWorker` / `startWithoutWorker`) nie zostaje wykonana.

Łańcuch awarii:
```
init()
  → await workerCreate()          → ReferenceError → catch → OK, idziemy dalej
  → await canvasCreate()
      → this[checkOffscreenCanvasSupported()]()     → ReferenceError → catch
      → ani startWithWorker ani startWithoutWorker się nie wykonały
      → canvas 3D nie istnieje, loader widoczny na zawsze
```

Dodatkowo `reloadModel3D()` (woływany przy każdej zmianie formularza) zakłada że canvas istnieje:
```js
const renderUUID = form?.querySelector('canvas')?.getAttribute('data-uuid');
const windowData = JSON.parse(JSON.stringify(window.data(renderUUID)));
// renderUUID = undefined → window.data(undefined) → JSON.parse(undefined) → SyntaxError
```

## Rozwiązanie

4 zmiany w pliku `corps.js`. Ścieżka `startWithoutWorker` jest już napisana w kodzie — to oficjalny fallback. Fix tylko odblokowuje jej wywołanie.

---

### Fix 1: `workerCreate()` — zawsze resolvuj Promise

**Znajdź:**
```js
this.workerCreate = async () => new Promise(async (t, e) => {
    const l = document.createElement("canvas");
    const u = new OffscreenCanvas(1, 1);
    if (l.transferControlToOffscreen() && u.getContext("webgl")) {
        // ... kod tworzenia Workera ...
        t(this)
    }
})
```

**Zamień na:**
```js
this.workerCreate = async () => new Promise(async (t, e) => {
    try {
        if (typeof OffscreenCanvas === 'undefined') { t(this); return; }
        const l = document.createElement("canvas");
        const u = new OffscreenCanvas(1, 1);
        const canTransfer = l.transferControlToOffscreen && l.transferControlToOffscreen();
        const webglOK = u.getContext && u.getContext("webgl");
        if (!canTransfer || !webglOK) { t(this); return; }
        // ... kod tworzenia Workera (bez zmian) ...
        t(this);
    } catch(err) {
        t(this);
    }
})
```

---

### Fix 2: `checkOffscreenCanvasSupported()` — bezpieczny check

**Znajdź:**
```js
this.checkOffscreenCanvasSupported = () => {
    const t = document.createElement("canvas");
    const e = new OffscreenCanvas(1, 1);
    return t.transferControlToOffscreen() && e.getContext("webgl") ? "startWithWorker" : "startWithoutWorker";
}
```

**Zamień na:**
```js
this.checkOffscreenCanvasSupported = () => {
    if (typeof OffscreenCanvas === 'undefined') return "startWithoutWorker";
    try {
        const t = document.createElement("canvas");
        const e = new OffscreenCanvas(1, 1);
        if (!t.transferControlToOffscreen || !e.getContext) return "startWithoutWorker";
        return t.transferControlToOffscreen() && e.getContext("webgl") ? "startWithWorker" : "startWithoutWorker";
    } catch {
        return "startWithoutWorker";
    }
}
```

---

### Fix 3: `reloadModel3D()` — ochrona przed brakiem canvas

**Znajdź:**
```js
function reloadModel3D() {
    const form = document.getElementById('cabinet_form_data');
    const formData = new FormData(form);
    const formDataObject = {};
    for (const [key, value] of formData.entries()) {
        if (formDataObject.hasOwnProperty(key)) {
            if (Array.isArray(formDataObject[key])) {
                formDataObject[key].push(value);
            } else {
                formDataObject[key] = [formDataObject[key], value];
            }
        } else {
            formDataObject[key] = value;
        }
    }
    const renderUUID = form?.querySelector('canvas')?.getAttribute('data-uuid');
    const windowData = JSON.parse(JSON.stringify(window.data(renderUUID)));
    // ...
}
```

**Zamień na:**
```js
function reloadModel3D() {
    const form = document.getElementById('cabinet_form_data');
    const canvas = form?.querySelector('canvas');
    if (!canvas) return;
    const renderUUID = canvas.getAttribute('data-uuid');
    const data = window.data(renderUUID);
    if (!data) return;
    const formData = new FormData(form);
    const formDataObject = {};
    for (const [key, value] of formData.entries()) {
        if (formDataObject.hasOwnProperty(key)) {
            if (Array.isArray(formDataObject[key])) {
                formDataObject[key].push(value);
            } else {
                formDataObject[key] = [formDataObject[key], value];
            }
        } else {
            formDataObject[key] = value;
        }
    }
    const windowData = JSON.parse(JSON.stringify(data));
    // ... reszta bez zmian
}
```

---

### Fix 4: CSS — touch events na canvas

**Dodaj do `style.css` / `style_common.css` / `safari.css`:**
```css
.model_3d canvas {
    touch-action: none;
}
```

Bez tego Safari iOS przechwytuje gesty (scroll, pinch-zoom) zamiast przekazywać je do Three.js OrbitControls.

---

## Co zadziała po fixie

| Funkcja | Status |
|---|---|
| Model 3D się wyświetli (nie wisi na loaderze) | ✅ |
| Obracanie modelem palcem | ✅ |
| Zoom pinch-to-zoom | ✅ |
| Zmiana wymiarów odświeża model | ✅ |
| Wybór materiałów, oklein, uchwytów, nóg | ✅ |
| Dodawanie do koszyka | ✅ |
| Generowanie instrukcji montażu | ✅ |

## Różnica między `startWithWorker` a `startWithoutWorker`

| | `startWithWorker` | `startWithoutWorker` |
|---|---|---|
| Gdzie leci rendering | Web Worker (osobny wątek) | Główny wątek strony |
| 3D / WebGL | ✅ | ✅ |
| OrbitControls (obrót, zoom) | ✅ | ✅ |
| Touch events | ✅ | ✅ |
| `reloadModel3D()` | ✅ | ✅ |
| Wydajność przy złożonych scenach | Lepsza | Nieco gorsza |

Dla konfiguratora szafki z kilkoma elementami różnica w wydajności jest niezauważalna.

## Weryfikacja

Po wdrożeniu przetestuj przez Playwright WebKit na Ubuntu:

```bash
npm install playwright && npx playwright install webkit
```

```js
// test.mjs
import { webkit } from 'playwright';
const browser = await webkit.launch();
const ctx = await browser.newContext({
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X)',
    viewport: { width: 390, height: 844 },
    isMobile: true, hasTouch: true
});
const page = await ctx.newPage();
page.on('console', m => console.log('📱', m.text()));
page.on('pageerror', e => console.log('❌', e.message));
await page.goto('https://meblosfera-konfigurator.kronosfera.pl/k/komoda-alfa,3682');
await page.waitForTimeout(5000);

const ok = await page.evaluate(() => ({
    canvas: !!document.querySelector('canvas'),
    loader: document.querySelector('#load_model_3D')?.classList.contains('active') ? 'widoczny' : 'ukryty',
}));
console.table(ok);
await browser.close();
```

Jeśli `canvas: true` i `loader: ukryty` — fix działa.
