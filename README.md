# AI Chat

Jednoduchá ChatGPT-style webová aplikace postavená na čistém PHP a vanilla JS. Používá Google AI Studio API s modely Gemma 3.

## Funkce

- Chat rozhraní s bublinami (uživatel vpravo, AI vlevo)
- Napojení na Google AI Studio API (Gemma 3 27B / 12B / 4B)
- Historie konverzace uložená v `chat.json`
- Kontext celé konverzace se posílá do API
- AJAX komunikace bez reloadu stránky
- Typing indikátor během čekání na odpověď
- Přepínání modelů (dropdown)
- Tlačítko pro smazání chatu
- Tmavý moderní design s glassmorphism efektem
- Responzivní layout

## Požadavky

- PHP 8.0+ s rozšířením `openssl` (nebo `curl`)
- API klíč z [Google AI Studio](https://aistudio.google.com/apikey)

## Instalace

1. Klonuj repozitář:
   ```bash
   git clone https://github.com/UZIVATEL/aisek.git
   cd aisek
   ```

2. Nastav API klíč — buď env proměnnou:
   ```bash
   export GOOGLE_AI_API_KEY="tvuj-api-klic"
   ```
   nebo přímo v `index.php` na řádku 15.

3. Spusť PHP server:
   ```bash
   php -S localhost:8000
   ```

4. Otevři `http://localhost:8000` v prohlížeči.

## Nasazení na hosting

Nahraj soubory `index.php` a `style.css` na PHP hosting. Vytvoř prázdný soubor `chat.json` s obsahem `[]` a nastav mu práva pro zápis (`chmod 666`).

## Struktura

```
index.php   – PHP backend + HTML + JS frontend
style.css   – styly aplikace
chat.json   – historie konverzace (generuje se automaticky)
```

## Licence

MIT
