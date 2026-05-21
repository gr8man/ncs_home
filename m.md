# Fix: Konfigurator 3D — iOS Safari crash & hang

## Objaw

Na iPhone z **iOS < 16.4** konfigurator 3D wisi na loaderze. Model się nie wyświetla, formularz nie reaguje na zmiany.

**Na iOS 16.4+ konfigurator działa poprawnie bez żadnych poprawek** — `OffscreenCanvas` jest wspierany od tej wersji.

## Potwierdzone testami (Playwright Chromium)

| Test | iOS 15.0 (bez OffscreenCanvas) | iOS 16.4 |
|---|---|---|
| Błędy | ❌ `OffscreenCanvas is not defined` | ✅ Brak |
| Canvas 3D | ❌ Nie istnieje | ✅ 900x900 |
| Koszyk | ❌ Zablokowany | ✅ Aktywny |
| Log | (brak) | `using OffscreenCanvas` |

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

Test przez Playwright (symulacja iOS 15.0 i 16.4):

```bash
npm install playwright && npx playwright install chromium
```

```js
// test-compare.mjs
import { chromium } from 'playwright';

async function testIOS(label, userAgent, removeOffscreenCanvas) {
    const browser = await chromium.launch();
    const ctx = await browser.newContext({
        userAgent, viewport: { width: 390, height: 844 },
        isMobile: true, hasTouch: true
    });
    const page = await ctx.newPage();
    if (removeOffscreenCanvas) {
        await page.addInitScript(() => {
            delete window.OffscreenCanvas;
            delete HTMLCanvasElement.prototype.transferControlToOffscreen;
        });
    }
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto('https://meblosfera-konfigurator.kronosfera.pl/k/komoda-alfa,3682', {
        waitUntil: 'networkidle', timeout: 60000
    });
    await page.waitForTimeout(10000);
    const state = await page.evaluate(() => {
        const canvas = document.querySelector('canvas');
        const cartBtn = document.getElementById('corp_cart_add');
        const loader = document.querySelector('#load_model_3D');
        return {
            canvas_exists: !!canvas,
            cart_disabled: cartBtn?.disabled,
            loader_active: loader?.classList.contains('active'),
        };
    });
    await browser.close();
    return { label, errors: errors.length, state };
}

const ios15 = await testIOS('iOS 15.0', 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X)', true);
const ios164 = await testIOS('iOS 16.4', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_4 like Mac OS X)', false);
console.log('iOS 15.0:', ios15.state.canvas_exists ? 'Dziala' : 'NIE dziala', `(bledy: ${ios15.errors})`);
console.log('iOS 16.4:', ios164.state.canvas_exists ? 'Dziala' : 'NIE dziala', `(bledy: ${ios164.errors})`);
```

**Oczekiwany wynik:**
- iOS 15.0: `NIE dziala` (canvas: false, błędy: 1)
- iOS 16.4: `Dziala` (canvas: true, błędy: 0)
