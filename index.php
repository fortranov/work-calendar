<?php
require __DIR__ . '/db.php';

$page = $_GET['page'] ?? 'home';
$page = $page === 'stats' ? 'stats' : 'home';

?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>График дежурств</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body data-page="<?php echo htmlspecialchars($page, ENT_QUOTES); ?>">
    <header class="site-header">
        <nav class="top-nav">
            <a href="index.php" class="nav-link <?php echo $page === 'home' ? 'active' : ''; ?>">Главная</a>
            <a href="index.php?page=stats" class="nav-link <?php echo $page === 'stats' ? 'active' : ''; ?>">Статистика</a>
        </nav>
    </header>

    <main class="page-content">
        <?php if ($page === 'home'): ?>
            <section class="calendar-section">
                <div class="calendar-controls">
                    <div class="month-switcher">
                        <button type="button" id="prev-month" class="control-button">◀</button>
                        <div id="current-month" class="current-month"></div>
                        <button type="button" id="next-month" class="control-button">▶</button>
                    </div>
                    <div class="toggle-edit">
                        <label>
                            <input type="checkbox" id="edit-toggle">
                            Режим редактирования
                        </label>
                    </div>
                    <div class="calendar-actions">
                        <button type="button" id="distribute" class="primary-button">Распределить</button>
                        <button type="button" id="clear-duty" class="danger-button">Очистить</button>
                        <button type="button" id="generate-report" class="secondary-button">Отчет</button>
                        <button type="button" id="add-participant" class="secondary-button">Добавить участника</button>
                    </div>
                </div>
                <div id="info-message" class="info-message" role="status"></div>
                <div id="calendar-container" class="calendar-container"></div>
            </section>
        <?php else: ?>
            <section class="stats-section">
                <div class="stats-controls">
                    <label for="stats-year">Год:</label>
                    <select id="stats-year" class="stats-select"></select>
                </div>
                <div class="stats-table-wrapper">
                    <table class="stats-table">
                        <thead>
                            <tr>
                                <th>ФИО</th>
                                <th>Пн</th>
                                <th>Вт</th>
                                <th>Ср</th>
                                <th>Чт</th>
                                <th>Пт</th>
                                <th>Сб</th>
                                <th>Вс</th>
                                <th>Отпуск</th>
                                <th>Больничный</th>
                                <th>Всего</th>
                            </tr>
                        </thead>
                        <tbody id="stats-body"></tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <div id="event-menu" class="event-menu hidden">
        <button type="button" data-type="duty">Дежурство</button>
        <button type="button" data-type="important">Важный день</button>
        <button type="button" data-type="vacation">Отпуск</button>
        <button type="button" data-type="trip">Командировка</button>
        <button type="button" data-type="sick">Больничный</button>
    </div>

    <div id="confirm-modal" class="modal hidden" role="dialog" aria-modal="true">
        <div class="modal-content">
            <div class="modal-message" id="modal-message"></div>
            <div class="modal-actions">
                <button type="button" id="modal-confirm" class="primary-button">Да</button>
                <button type="button" id="modal-cancel" class="secondary-button">Нет</button>
            </div>
        </div>
    </div>

    <script src="assets/app.js"></script>
</body>
</html>
