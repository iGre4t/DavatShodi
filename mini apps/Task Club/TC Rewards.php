<?php
session_start();
$cspNonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$cspNonce}'; style-src 'self' 'nonce-{$cspNonce}'; img-src 'self' data: https: http:; font-src 'self' data:; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: same-origin");

if (!(isset($_SESSION['tc_authed']) && $_SESSION['tc_authed'] === true)) {
  header('Location: TCM.php');
  exit;
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>جوایز باشگاه</title>
    <style nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>">
      @font-face {
        font-family: 'Peyda Fa Num';
        src: url('../../assets/fonts/PeydaWebFaNum-Regular.woff2') format('woff2');
        font-weight: 400;
        font-style: normal;
      }

      @font-face {
        font-family: 'Peyda Fa Num';
        src: url('../../assets/fonts/PeydaWebFaNum-Bold.woff2') format('woff2');
        font-weight: 700;
        font-style: normal;
      }

      :root {
        color-scheme: light;
        --bg: #eef4ff;
        --phone: #ffffff;
        --line: #dce6f8;
      }

      * {
        box-sizing: border-box;
      }

      body {
        margin: 0;
        min-height: 100vh;
        background: radial-gradient(circle at 15% 8%, #f8fbff 0%, #e8f0ff 52%, #dce8ff 100%);
        font-family: 'Peyda Fa Num', sans-serif;
        color: #20365c;
        display: grid;
        place-items: center;
        padding: 18px;
      }

      .app {
        width: min(460px, 100%);
      }

      .phone {
        width: 100%;
        min-height: min(860px, calc(100vh - 36px));
        background: var(--phone);
        border: 1px solid var(--line);
        border-radius: 28px;
        box-shadow: 0 26px 50px rgba(29, 55, 96, 0.14), inset 0 1px 0 #fff;
        overflow: hidden;
        padding: 18px 16px 24px;
      }

      .title {
        margin: 0;
        text-align: center;
        font-size: 1.1rem;
        color: #2b4370;
      }

      .cards-box {
        margin: 16px auto 0;
        width: min(360px, calc(100vw - 64px));
        border-radius: 18px;
        border: 1px solid #d9e7fb;
        background: linear-gradient(160deg, #f8fbff, #edf4ff 56%, #f9fcff);
        box-shadow: 0 16px 30px rgba(44, 86, 146, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.84);
        padding: 14px;
      }

      .cards-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
        pointer-events: none;
      }

      .flip-card {
        perspective: 700px;
      }

      .flip-card-inner {
        position: relative;
        width: 100%;
        padding-top: 125%;
        transform-style: preserve-3d;
        transform: rotateY(180deg);
      }

      .flip-face {
        position: absolute;
        inset: 0;
        border-radius: 12px;
        border: 1px solid #c7d8f5;
        backface-visibility: hidden;
      }

      .flip-front {
        background: linear-gradient(150deg, #fefefe, #edf3ff);
      }

      .flip-back {
        transform: rotateY(180deg);
        background: linear-gradient(150deg, #1f4f96, #2d78d6 52%, #54a9f0 100%);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.45);
      }

      .result {
        width: min(360px, calc(100vw - 64px));
        margin: 18px auto 0;
        border: 1px solid #dce7f9;
        border-radius: 16px;
        background: #f8fbff;
        box-shadow: 0 16px 30px rgba(44, 86, 146, 0.1), inset 0 1px 0 rgba(255, 255, 255, 0.86);
        padding: 14px 12px;
      }

      .result-label {
        display: block;
        min-height: 1em;
      }

      .result-value {
        margin: 6px 0 0;
        min-height: 32px;
      }

      @media (max-width: 440px) {
        body {
          padding: 10px;
        }

        .phone {
          border-radius: 22px;
          min-height: calc(100vh - 20px);
        }

        .cards-box,
        .result {
          width: min(296px, calc(100vw - 52px));
        }
      }

      @supports (height: 100dvh) {
        .phone {
          min-height: min(860px, calc(100dvh - 36px));
        }
      }
    </style>
  </head>
  <body>
    <main class="app">
      <section class="phone">
        <h1 class="title">جوایز باشگاه</h1>
        <div class="cards-box" aria-label="جعبه کارت‌ها">
          <div class="cards-grid" aria-hidden="true">
            <?php for ($i = 0; $i < 9; $i += 1): ?>
              <div class="flip-card">
                <div class="flip-card-inner">
                  <div class="flip-face flip-front"></div>
                  <div class="flip-face flip-back"></div>
                </div>
              </div>
            <?php endfor; ?>
          </div>
        </div>
        <div class="result" aria-label="نتیجه">
          <span class="result-label"></span>
          <p class="result-value"></p>
        </div>
      </section>
    </main>
  </body>
</html>
