      margin: 0 0 5px;
      font-family: 'Peyda';
      font-size: clamp(1.4rem, 3vw, 1.8rem);
      letter-spacing: 0.35em;
      font-weight: 600;
      color: #0f172a;
      direction: ltr;
      display: block;
      line-height: 1.1;
      width: 100%;
      text-align: center;
      white-space: nowrap;
    }

    @media (max-width: 480px) {
      .device {
        width: min(340px, 95vw);
        border-radius: 28px;
      }
      .message {
        padding: 1.25rem 1rem 1.6rem;
      }
      .code {
        letter-spacing: 0.3em;
        font-size: clamp(1.8rem, 4vw, 2.4rem);
      }
      .name {
        font-size: clamp(1.5rem, 4vw, 1.9rem);
      }
    }
</style>
</head>
  <body>
    <div class="device">
      <div class="screen">
        <div class="card-image-shell">
          <img src="{$imageUrl}" alt="???? ???? ??????">
        </div>
        <div class="message">
          <p class="greeting">مهمان محترم</p>
          <p class="name">{$safeName}</p>
          {$qrElement}
          <p class="code">{$persianCode}</p>
        </div>
      </div>
    </div>
  </body>
  </html>
HTML;
        if (@file_put_contents($guestDir . '/index.php', $page) === false) {
            error_log('Failed to write invite page for ' . $code);
        }
    }
}

