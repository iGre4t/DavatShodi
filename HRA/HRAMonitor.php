<?php
?>
<!doctype html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>HRA Monitor</title>
    <meta name="color-scheme" content="light" />
    <script src="../style/appearance.js"></script>
    <link rel="stylesheet" href="../style/styles.css" />
    <link rel="stylesheet" href="../style/remixicon.css" />
    <style>
      .hra-monitor {
        display: grid;
        grid-template-columns: 230px minmax(0, 1fr);
        min-height: 100vh;
        background: var(--bg);
      }
      .hra-sidebar {
        padding: 12px 10px;
        width: 230px;
      }
      .hra-sidebar .sidebar-header {
        padding: 4px 6px 10px;
      }
      .hra-filters {
        display: grid;
        gap: 16px;
        padding: 0 6px;
      }
      .hra-filters select[multiple] {
        min-height: 160px;
        background-image: none;
        padding-right: 12px;
        padding-inline-end: 12px;
      }
      .hra-content {
        padding: 24px;
      }
      .hra-content .card + .card {
        margin-top: 16px;
      }
      @media (max-width: 900px) {
        .hra-monitor {
          grid-template-columns: 1fr;
        }
        .hra-sidebar {
          width: 100%;
          height: auto;
          position: static;
        }
      }
    </style>
  </head>
  <body>
    <div class="hra-monitor">
      <aside class="sidebar hra-sidebar">
        <div class="sidebar-header">
          <div class="title">HRA Monitor</div>
        </div>
        <div class="hra-filters">
          <label class="field">
            <span>دپارتمان‌ها (نمونه)</span>
            <select multiple size="6">
              <option>منابع انسانی</option>
              <option>فروش</option>
              <option>مالی</option>
              <option>عملیات</option>
              <option>فنی</option>
              <option>پشتیبانی</option>
            </select>
          </label>
          <label class="field">
            <span>سطح عملکرد (نمونه)</span>
            <select multiple size="5">
              <option>عالی</option>
              <option>خوب</option>
              <option>متوسط</option>
              <option>نیازمند بهبود</option>
              <option>بحرانی</option>
            </select>
          </label>
          <label class="field">
            <span>بازه زمانی (نمونه)</span>
            <select multiple size="4">
              <option>هفتگی</option>
              <option>ماهانه</option>
              <option>فصلی</option>
              <option>سالانه</option>
            </select>
          </label>
        </div>
      </aside>
      <main class="hra-content">
        <div class="card">
          <h3>داشبورد HRA Monitor</h3>
          <p class="muted">این صفحه برای نمایش وضعیت پایش طراحی شده است. داده‌ها و ویجت‌ها در نسخه‌های بعدی اضافه می‌شوند.</p>
        </div>
        <div class="card">
          <h3>خلاصه نمونه</h3>
          <p class="muted">اینجا می‌توانید گراف‌ها یا گزارش‌های نمونه قرار دهید.</p>
        </div>
      </main>
    </div>
  </body>
</html>
