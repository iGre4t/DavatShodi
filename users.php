<?php
declare(strict_types=1);

require_once __DIR__ . '/api/lib/tab-permissions.php';
requireTabPermissionFromSession('users', false);
?>

<section id="tab-users" class="tab">
  <div class="card">
    <div class="table-header">
      <h3>کاربران</h3>
      <button class="btn primary" id="add-user">افزودن کاربر</button>
    </div>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>کد یکتای کاربر</th>
            <th>نام کامل</th>
            <th>شماره تلفن</th>
            <th>کد پرسنلی</th>
            <th>کد ملی</th>
            <th>ایمیل</th>
            <th>عملیات</th>
          </tr>
        </thead>
        <tbody id="users-body"></tbody>
      </table>
    </div>
  </div>
</section>
