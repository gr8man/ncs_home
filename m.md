# Fix: Konfigurator 3D — iOS Safari crash & hang

## Objaw

Na iPhone (iOS < 16.4) konfigurator 3D wisi na loaderze. Model się nie wyświetla, formularz nie reaguje na zmiany.

## Przyczyna

Plik: `admin.kronosfera.erozrys.pl/assets/js_pages/corps/dist/corps.js`

### Błąd #1: `workerCreate()` — Promise nigdy się nie rozwiązuje

```js
this.workerCreate = async () => new Promise(async (t, e) => {
    const l = document.createElement("canvas");
    const u = new OffscreenCanvas(1, 1);  // ← ReferenceError na iOS < 16.4
    if (l.transferControlToOffscreen() && u.getContext("webgl")) {
        // ... tworzy Workera ...
        t(this);
    }
    // ← BRAK else — Promise wisi w nieskończoność
});
```

Na iOS < 16.4 `OffscreenCanvas` nie istnieje → `ReferenceError` → Promise odrzucony → `catch` łapie → `canvasCreate()` woła `checkOffscreenCanvasSupported()` → ta też rzuca → **ani `startWithWorker` ani `startWithoutWorker` nie działają**.

### Błąd #2: `checkOffscreenCanvasSupported()` — rzuca zamiast zwrócić fallback

```js
this.checkOffscreenCanvasSupported = () => {
    const t = document.createElement("canvas");
    const e = new OffscreenCanvas(1, 1);  // ← ReferenceError
    return t.transferControlToOffscreen() && e.getContext("webgl")
        ? "startWithWorker" : "startWithoutWorker";
}
```

### Błąd #3: Vue Canvas component — `dl.worker` jest undefined

W ścieżce `startWithoutWorker` worker nie jest tworzony, ale Vue Canvas component robi:
```js
dl.worker.addEventListener("message", ...)  // ← TypeError: undefined
```

### Błąd #4: `startWithoutWorker` — `modelready` nie dochodzi do main thread

Ścieżka `startWithoutWorker` ładuje kod workera przez dynamic import do main threadu. Kod workera dispatchuje `modelready` przez `dispatchEvent(new CustomEvent("modelready"))`, ale w kontekście main threadu event nie dochodzi do listenera na `window`. Dodatkowo `postMessage` w main threadzie działa inaczej niż w workerze.

---

## Rozwiązanie

### Fix 1: `workerCreate()` — zawsze resolvuj Promise

**Znajdź:**
```js
this.workerCreate=async()=>new Promise(async(t,e)=>{var n,s,i,r,o,a,c;const l=document.createElement("canvas"),u=new OffscreenCanvas(1,1);if(l.transferControlToOffscreen()&&u.getContext("webgl")){
```

**Zamień na:**
```js
this.workerCreate=async()=>new Promise(async(t,e)=>{var n,s,i,r,o,a,c;const l=document.createElement("canvas");if(typeof OffscreenCanvas!=="undefined"){const u=new OffscreenCanvas(1,1);if(l.transferControlToOffscreen()&&u.getContext("webgl")){
```

**I znajdź koniec bloku if:**
```js
t(this)}}),this.checkOffscreenCanvasSupported
```

**Zamień na:**
```js
t(this)}}else{t(this)}}),this.checkOffscreenCanvasSupported
```

### Fix 2: `checkOffscreenCanvasSupported()` — bezpieczny check

**Znajdź:**
```js
this.checkOffscreenCanvasSupported=()=>{const t=document.createElement("canvas"),e=new OffscreenCanvas(1,1);return t.transferControlToOffscreen()&&e.getContext("webgl")?"startWithWorker":"startWithoutWorker"}
```

**Zamień na:**
```js
this.checkOffscreenCanvasSupported=()=>{if(typeof OffscreenCanvas==="undefined")return "startWithoutWorker";try{const t=document.createElement("canvas"),e=new OffscreenCanvas(1,1);return t.transferControlToOffscreen()&&e.getContext("webgl")?"startWithWorker":"startWithoutWorker"}catch{return "startWithoutWorker"}}
```

### Fix 3: Vue Canvas component — guard na `dl.worker`

**Znajdź:**
```js
dl.worker.addEventListener("message",function(e){var n;(null===(n=null==e?void 0:e.data)||void 0===n?void 0:n.cameraSettings)
```

**Zamień na:**
```js
dl.worker&&dl.worker.addEventListener("message",function(e){var n;(null===(n=null==e?void 0:e.data)||void 0===n?void 0:n.cameraSettings)
```

### Fix 4: `startWithoutWorker` — dispatch `modelready` na window

W `startWithoutWorker`, po załadowaniu modeli, `modelready` musi być dispatchowany na `window` (nie na `self` który w workerze jest inny). Kod workera już robi `dispatchEvent(new CustomEvent("modelready"))` co w main threadzie działa na `window`. Problem jest że `cabinetLoaded()` może nie być wołane.

**Dodatkowo w `startWithoutWorker`**, po `console.info("using Canvas")` dodaj:
```js
dl.setWorker(null);
```

### Fix 5: CSS — touch events

Dodaj do `style.css`:
```css
.model_3d canvas { touch-action: none; }
```

### Fix 6: `reloadModel3D()` — ochrona przed brakiem canvas

W inline script na stronie, znajdź `reloadModel3D()` i dodaj guard:
```js
function reloadModel3D() {
    const form = document.getElementById('cabinet_form_data');
    const canvas = form?.querySelector('canvas');
    if (!canvas) return;
    // ... reszta
}
```

---

## Co zadziała po fixach 1-3

| Funkcja | Status |
|---|---|
| Canvas 3D istnieje | ✅ |
| Canvas ma poprawny rozmiar (900x900) | ✅ |
| WebGL context działa | ✅ |
| Brak błędów Vue | ✅ |
| Loader ukryty | ✅ |
| `modelready` event | ⚠️ Wymaga Fix 4 |
| Przycisk "Dodaj do koszyka" aktywny | ⚠️ Wymaga Fix 4 |

## Uwaga o `startWithoutWorker`

Ścieżka `startWithoutWorker` jest **częściowo funkcjonalna**. Kod workera ładowany przez dynamic import do main threadu ma problemy z:
- `postMessage` — w main threadzie wysyła do window, nie do parent worker
- Event dispatching — `dispatchEvent` na `self` (window) może nie dochodzić do listenerów

Po fixach 1-3 konfigurator **inicjalizuje się poprawnie** (canvas istnieje, WebGL działa), ale pełne renderowanie sceny 3D i event `modelready` mogą wymagać dodatkowych poprawek w ścieżce `startWithoutWorker`.

**Rekomendacja:** Po wdrożeniu fixów 1-3 przetestować na fizycznym iPhonie. Jeśli model się renderuje ale `modelready` nie odpala, dodać ręczny dispatch w `startWithoutWorker`:

```js
// W startWithoutWorker, po importsDone:
self.addEventListener("importsDone", () => {
    self.dispatchEvent(new CustomEvent("init", { ... }));
});

// Dodać listener na cabinetComplete/modelready i redispatch na window:
addEventListener("modelready", () => {
    window.dispatchEvent(new CustomEvent("modelready"));
});
```

---

## Weryfikacja

Test przez Playwright WebKit (symulacja iOS 15.0):

```bash
npm install playwright && npx playwright install webkit
```

```js
// test.mjs
import { chromium } from 'playwright';
const browser = await chromium.launch();
const ctx = await browser.newContext({
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X)',
    viewport: { width: 390, height: 844 },
    isMobile: true, hasTouch: true
});
const page = await ctx.newPage();

// Symulacja iOS < 16.4
await page.addInitScript(() => {
    delete window.OffscreenCanvas;
    delete HTMLCanvasElement.prototype.transferControlToOffscreen;
});

page.on('pageerror', e => console.log('❌', e.message));
await page.goto('https://meblosfera-konfigurator.kronosfera.pl/k/komoda-alfa,3682');
await page.waitForTimeout(10000);

const ok = await page.evaluate(() => ({
    canvas: !!document.querySelector('canvas'),
    canvas_size: document.querySelector('canvas')?.width + 'x' + document.querySelector('canvas')?.height,
    errors: document.querySelectorAll('.error').length,
}));
console.table(ok);
await browser.close();
```

Oczekiwany wynik po fixach: `canvas: true`, `canvas_size: 900x900` (lub 600x600), brak błędów.
