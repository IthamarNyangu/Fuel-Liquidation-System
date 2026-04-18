<?php
$appRoot = dirname(__DIR__, 2);
require_once $appRoot . '/auth_check.php';
require_once $appRoot . '/fleet_redesign.php';

$user = fleet_current_user_context();
$isDriver = fleet_is_driver($user);
$pageTitle = 'Reports';
$subtitle = 'Coming soon';

if ($isDriver) {
    fleet_render_shell_start($pageTitle, 'reports', $user, '');

    echo '<section class="panel empty-state">';
    echo '<div class="empty-state-icon"><i class="fas fa-chart-line"></i></div>';
    echo '<h2>Reports Coming Soon</h2>';
    echo '<p>This module is still under development. The previous reports logic has been cleared so a new reporting experience can be added cleanly later.</p>';
    echo '</section>';

    fleet_render_shell_end();
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports</title>
    <?php require $appRoot . '/favicon_links.php'; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --primary: #35627c;
            --primary-dark: #29485d;
            --surface: rgba(255, 255, 255, 0.96);
            --border: rgba(53, 98, 124, 0.16);
            --text: #1f2933;
            --muted: #66768a;
            --background: radial-gradient(circle at top right, rgba(76, 127, 153, 0.12), transparent 24%), linear-gradient(145deg, #f7f7f5 0%, #f6f8fa 46%, #eef3f6 100%);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text);
            background: var(--background);
            padding: 32px;
        }

        .reports-shell {
            max-width: 1100px;
            margin: 0 auto;
        }

        .reports-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
        }

        .reports-title h1 {
            margin: 0;
            font-size: 2rem;
            line-height: 1.1;
        }

        .reports-title p {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 1rem;
        }

        .reports-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text);
            text-decoration: none;
            font-weight: 600;
            transition: background-color 0.2s ease, border-color 0.2s ease, color 0.2s ease;
        }

        .reports-back:hover {
            background: #f4f8fb;
            border-color: rgba(53, 98, 124, 0.28);
            color: var(--primary-dark);
        }

        .reports-panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 24px;
            box-shadow: 0 18px 44px rgba(22, 32, 42, 0.08);
            padding: 56px 32px;
            text-align: center;
        }

        .reports-icon {
            width: 72px;
            height: 72px;
            margin: 0 auto 20px;
            border-radius: 20px;
            display: grid;
            place-items: center;
            background: rgba(53, 98, 124, 0.1);
            color: var(--primary);
            font-size: 1.8rem;
        }

        .reports-panel h2 {
            margin: 0 0 12px;
            font-size: 1.9rem;
        }

        .reports-panel p {
            max-width: 620px;
            margin: 0 auto;
            color: var(--muted);
            line-height: 1.7;
        }

        @media (max-width: 720px) {
            body {
                padding: 20px;
            }

            .reports-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .reports-panel {
                padding: 40px 22px;
            }
        }
    </style>
</head>
<body>
    <div class="reports-shell">
        <header class="reports-header">
            <div class="reports-title">
                <h1>Reports</h1>
                <p>Coming soon</p>
            </div>
            <a href="dashboard.php" class="reports-back">Back to Dashboard</a>
        </header>

        <section class="reports-panel">
            <div class="reports-icon"><i class="fas fa-chart-line"></i></div>
            <h2>Still Under Development</h2>
            <p>The previous reports implementation has been cleared so a new reporting module can be designed properly. The navigation link can stay in place while you decide what should live here.</p>
        </section>
    </div>
</body>
</html>
