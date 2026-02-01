<?php
$dataPath = __DIR__ . '/FNum.json';
$startNumber = '';
$endNumber = '';
$existingWinners = [];
$saveMessage = '';
$saveError = '';

if (is_file($dataPath)) {
  $raw = file_get_contents($dataPath);
  if ($raw !== false) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
      $startNumber = isset($decoded['start']) ? (string)$decoded['start'] : '';
      $endNumber = isset($decoded['end']) ? (string)$decoded['end'] : '';
      $existingWinners = is_array($decoded['winners'] ?? null) ? array_values($decoded['winners']) : [];
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
  if ($action === 'clear_winners' || $action === 'delete_winner') {
    $updatedWinners = $existingWinners;
    if ($action === 'clear_winners') {
      $updatedWinners = [];
    } elseif ($action === 'delete_winner') {
      $index = isset($_POST['winner_index']) ? (int)$_POST['winner_index'] : -1;
      if ($index >= 0 && $index < count($updatedWinners)) {
        array_splice($updatedWinners, $index, 1);
      }
    }
    $payload = [
      'start' => $startNumber,
      'end' => $endNumber,
      'winners' => array_values($updatedWinners)
    ];
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $saved = false;
    if ($encoded === false) {
      $saveError = 'Save failed: invalid JSON payload.';
    } else {
      $result = @file_put_contents($dataPath, $encoded, LOCK_EX);
      if ($result === false) {
        $errorInfo = error_get_last();
        $saveError = $errorInfo['message'] ?? 'Save failed: unable to write file.';
      } else {
        $saved = true;
        $existingWinners = $updatedWinners;
      }
    }
    $saveMessage = $saved ? 'Saved.' : ($saveError ?: 'Save failed.');
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
      strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($isAjax) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode([
        'ok' => $saved,
        'message' => $saveMessage,
        'winners' => array_values($existingWinners)
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }

  $startNumber = isset($_POST['start_number']) ? trim((string)$_POST['start_number']) : '';
  $endNumber = isset($_POST['end_number']) ? trim((string)$_POST['end_number']) : '';
  $payload = [
    'start' => $startNumber,
    'end' => $endNumber,
    'winners' => $existingWinners
  ];
  $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  $saved = false;
  if ($encoded === false) {
    $saveError = 'Save failed: invalid JSON payload.';
  } else {
    $result = @file_put_contents($dataPath, $encoded, LOCK_EX);
    if ($result === false) {
      $errorInfo = error_get_last();
      $saveError = $errorInfo['message'] ?? 'Save failed: unable to write file.';
    } else {
      $saved = true;
    }
  }
  $saveMessage = $saved ? 'Saved.' : ($saveError ?: 'Save failed.');
  $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'ok' => $saved,
      'message' => $saveMessage
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }
}
?>

<div class="card">
  <div class="section-header">
    <h3>Control Panel</h3>
  </div>
  <form method="post" class="form" id="fnum-control-form">
    <div class="grid full">
      <label class="field">
        <span>Start number</span>
        <input type="number" name="start_number" value="<?= htmlspecialchars($startNumber, ENT_QUOTES, 'UTF-8') ?>" />
      </label>
      <label class="field">
        <span>End number</span>
        <input type="number" name="end_number" value="<?= htmlspecialchars($endNumber, ENT_QUOTES, 'UTF-8') ?>" />
      </label>
    </div>
    <div class="section-footer">
      <button type="submit" class="btn primary">Save</button>
    </div>
  </form>
</div>

<div class="card">
  <div class="section-header">
    <h3>List of Winners</h3>
  </div>
  <div class="section-footer" style="justify-content: flex-start;">
    <button type="button" class="btn danger" id="fnum-clear-winners">Clear All</button>
  </div>
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Winner Number</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody id="fnum-winners-body">
        <?php if (!empty($existingWinners)): ?>
          <?php foreach ($existingWinners as $idx => $winner): ?>
            <tr data-winner-index="<?= (int)$idx ?>">
              <td><?= htmlspecialchars((string)$winner, ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <button type="button" class="btn danger fnum-delete-winner" data-winner-index="<?= (int)$idx ?>">Delete</button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="2">No winners yet.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  (() => {
    const form = document.getElementById('fnum-control-form');
    if (!form) {
      return;
    }
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const formData = new FormData(form);
      try {
        const response = await fetch(form.action || window.location.href, {
          method: 'POST',
          body: formData,
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          }
        });
        let payload = null;
        try {
          payload = await response.json();
        } catch (error) {
          payload = null;
        }
        const ok = response.ok && payload && payload.ok;
        const message = (payload && payload.message) ? payload.message : (ok ? 'Saved.' : 'Save failed.');
        if (ok) {
          if (typeof window.showActionSnackbar === 'function') {
            window.showActionSnackbar({
              message,
              instructionLabel: '',
              shortcut: null
            });
          } else {
            alert(message);
          }
        } else if (typeof window.showErrorSnackbar === 'function') {
          window.showErrorSnackbar({ message });
        } else if (typeof window.showActionSnackbar === 'function') {
          window.showActionSnackbar({
            message,
            instructionLabel: '',
            shortcut: null
          });
        } else {
          alert(message);
        }
      } catch (error) {
        const message = (error && error.message) ? error.message : 'Save failed.';
        if (typeof window.showErrorSnackbar === 'function') {
          window.showErrorSnackbar({ message });
        } else if (typeof window.showActionSnackbar === 'function') {
          window.showActionSnackbar({
            message,
            instructionLabel: '',
            shortcut: null
          });
        } else {
          alert(message);
        }
      }
    });
    const winnersBody = document.getElementById('fnum-winners-body');
    const clearBtn = document.getElementById('fnum-clear-winners');

    const renderWinners = (items) => {
      if (!winnersBody) {
        return;
      }
      const rows = Array.isArray(items) ? items : [];
      winnersBody.innerHTML = '';
      if (!rows.length) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 2;
        td.textContent = 'No winners yet.';
        tr.appendChild(td);
        winnersBody.appendChild(tr);
        return;
      }
      rows.forEach((winner, index) => {
        const tr = document.createElement('tr');
        tr.dataset.winnerIndex = index.toString();
        const tdNumber = document.createElement('td');
        tdNumber.textContent = winner.toString();
        const tdAction = document.createElement('td');
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn danger fnum-delete-winner';
        btn.dataset.winnerIndex = index.toString();
        btn.textContent = 'Delete';
        tdAction.appendChild(btn);
        tr.appendChild(tdNumber);
        tr.appendChild(tdAction);
        winnersBody.appendChild(tr);
      });
    };

    const postAction = async (payload) => {
      const formData = new FormData();
      Object.keys(payload).forEach((key) => {
        formData.append(key, payload[key]);
      });
      const response = await fetch(form.action || window.location.href, {
        method: 'POST',
        body: formData,
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        }
      });
      const data = await response.json();
      if (!response.ok || !data || !data.ok) {
        throw new Error((data && data.message) ? data.message : 'Save failed.');
      }
      return data;
    };

    if (clearBtn) {
      clearBtn.addEventListener('click', async () => {
        try {
          const data = await postAction({ action: 'clear_winners' });
          renderWinners(data.winners || []);
        } catch (error) {
          const message = (error && error.message) ? error.message : 'Save failed.';
          if (typeof window.showErrorSnackbar === 'function') {
            window.showErrorSnackbar({ message });
          } else {
            alert(message);
          }
        }
      });
    }

    if (winnersBody) {
      winnersBody.addEventListener('click', async (event) => {
        const target = event.target;
        if (!target || !target.classList || !target.classList.contains('fnum-delete-winner')) {
          return;
        }
        const index = target.dataset.winnerIndex;
        if (index === undefined) {
          return;
        }
        try {
          const data = await postAction({ action: 'delete_winner', winner_index: index });
          renderWinners(data.winners || []);
        } catch (error) {
          const message = (error && error.message) ? error.message : 'Save failed.';
          if (typeof window.showErrorSnackbar === 'function') {
            window.showErrorSnackbar({ message });
          } else {
            alert(message);
          }
        }
      });
    }
  })();
</script>
