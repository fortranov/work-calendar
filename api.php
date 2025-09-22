<?php
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? null;

if ($action === null) {

    log_warning('Получен запрос без указания действия', [
        'keys' => array_keys($_POST),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
    http_response_code(400);
    echo json_encode(['error' => 'Неизвестное действие'], JSON_UNESCAPED_UNICODE);
    exit;
}

log_info('Начало обработки API-запроса', [
    'action' => $action,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
]);

try {
    $db = get_db();

    switch ($action) {
        case 'get_calendar':
            handleGetCalendar($db);
            break;
        case 'add_participant':
            handleAddParticipant($db);
            break;
        case 'delete_participant':
            handleDeleteParticipant($db);
            break;
        case 'reorder_participants':
            handleReorderParticipants($db);
            break;
        case 'save_event':
            handleSaveEvent($db);
            break;
        case 'delete_event':
            handleDeleteEvent($db);
            break;
        case 'auto_assign':
            handleAutoAssign($db);
            break;
        case 'clear_month_duties':
            handleClearMonthDuties($db);
            break;
        case 'get_statistics':
            handleGetStatistics($db);
            break;
        case 'generate_report':
            handleGenerateReport($db);
            break;
        default:
            log_warning('Запрошено неизвестное действие', [
                'action' => $action,
            ]);
            http_response_code(400);
            echo json_encode(['error' => 'Неизвестное действие'], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (Throwable $e) {
    log_error('Ошибка при обработке API-запроса', [
        'action' => $action,
        'error' => $e->getMessage(),
    ]);
    http_response_code(500);
    echo json_encode([
        'error' => 'Произошла ошибка',
        'details' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

function handleGetCalendar(PDO $db): void
{
    $month = max(1, min(12, (int) ($_POST['month'] ?? date('n'))));
    $year = (int) ($_POST['year'] ?? date('Y'));

    log_info('Запрошен календарь', [
        'month' => $month,
        'year' => $year,
    ]);

    $participants = fetchParticipants($db);
    [$startDate, $endDate] = monthBounds($year, $month);

    $stmt = $db->prepare('SELECT * FROM events WHERE NOT (date(end_date) < :start OR date(start_date) > :end)');
    $stmt->execute([
        ':start' => $startDate,
        ':end' => $endDate,
    ]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'participants' => $participants,
        'events' => $events,
        'month' => $month,
        'year' => $year,
    ], JSON_UNESCAPED_UNICODE);
}

function handleAddParticipant(PDO $db): void
{
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        log_warning('Попытка добавить участника с пустым именем');
        http_response_code(422);
        echo json_encode(['error' => 'Имя не может быть пустым'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $maxSort = (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) FROM participants')->fetchColumn();
    $stmt = $db->prepare('INSERT INTO participants (name, sort_order) VALUES (:name, :sort)');
    $stmt->execute([
        ':name' => $name,
        ':sort' => $maxSort + 1,
    ]);

    $id = (int) $db->lastInsertId();
    log_info('Добавлен новый участник', [
        'id' => $id,
        'name' => $name,
    ]);

    echo json_encode(['participant' => ['id' => $id, 'name' => $name]], JSON_UNESCAPED_UNICODE);
}

function handleDeleteParticipant(PDO $db): void
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        log_warning('Попытка удалить участника с некорректным идентификатором', [
            'id' => $id,
        ]);
        http_response_code(422);
        echo json_encode(['error' => 'Некорректный идентификатор'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $stmt = $db->prepare('DELETE FROM participants WHERE id = :id');
    $stmt->execute([':id' => $id]);

    log_info('Удалён участник', [
        'id' => $id,
    ]);

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
}

function handleReorderParticipants(PDO $db): void
{
    $order = $_POST['order'] ?? [];
    if (!is_array($order)) {
        log_warning('Попытка изменить порядок участников с некорректными данными');
        http_response_code(422);
        echo json_encode(['error' => 'Некорректный формат'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $stmt = $db->prepare('UPDATE participants SET sort_order = :sort WHERE id = :id');
    foreach ($order as $index => $id) {
        $stmt->execute([
            ':sort' => $index,
            ':id' => (int) $id,
        ]);
    }

    log_info('Обновлён порядок участников', [
        'order' => array_values(array_map('intval', $order)),
    ]);

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
}

function handleSaveEvent(PDO $db): void
{
    $participantId = (int) ($_POST['participant_id'] ?? 0);
    $type = trim((string) ($_POST['type'] ?? ''));
    $start = (string) ($_POST['start_date'] ?? '');
    $end = (string) ($_POST['end_date'] ?? '');

    if ($participantId <= 0 || $type === '' || $start === '') {
        log_warning('Попытка сохранить событие с неполными данными', [
            'participant_id' => $participantId,
            'type' => $type,
            'start' => $start,
            'end' => $end,
        ]);
        http_response_code(422);
        echo json_encode(['error' => 'Недостаточно данных'], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($end === '') {
        $end = $start;
    }

    if (!validateDate($start) || !validateDate($end) || $end < $start) {
        log_warning('Попытка сохранить событие с некорректными датами', [
            'start' => $start,
            'end' => $end,
        ]);
        http_response_code(422);
        echo json_encode(['error' => 'Некорректные даты'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $allowed = ['duty', 'important', 'vacation', 'trip', 'sick'];
    if (!in_array($type, $allowed, true)) {
        log_warning('Попытка сохранить событие с неизвестным типом', [
            'type' => $type,
        ]);
        http_response_code(422);
        echo json_encode(['error' => 'Неизвестный тип события'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM events WHERE participant_id = :pid AND NOT (date(end_date) < :start OR date(start_date) > :end)'
    );
    $stmt->execute([
        ':pid' => $participantId,
        ':start' => $start,
        ':end' => $end,
    ]);
    $overlaps = (int) $stmt->fetchColumn();
    if ($overlaps > 0) {
        log_warning('Попытка сохранить пересекающееся событие', [
            'participant_id' => $participantId,
            'start' => $start,
            'end' => $end,
        ]);
        http_response_code(409);
        echo json_encode(['error' => 'Событие пересекается с существующим'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $stmt = $db->prepare('INSERT INTO events (participant_id, type, start_date, end_date) VALUES (:pid, :type, :start, :end)');
    $stmt->execute([
        ':pid' => $participantId,
        ':type' => $type,
        ':start' => $start,
        ':end' => $end,
    ]);

    $eventId = (int) $db->lastInsertId();
    $stmt = $db->prepare('SELECT * FROM events WHERE id = :id');
    $stmt->execute([':id' => $eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    log_info('Сохранено событие', [
        'event' => $event,
    ]);

    echo json_encode(['event' => $event], JSON_UNESCAPED_UNICODE);
}

function handleDeleteEvent(PDO $db): void
{
    $eventId = (int) ($_POST['id'] ?? 0);
    if ($eventId <= 0) {
        log_warning('Попытка удалить событие с некорректным идентификатором', [
            'id' => $eventId,
        ]);
        http_response_code(422);
        echo json_encode(['error' => 'Некорректный идентификатор'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $stmt = $db->prepare('DELETE FROM events WHERE id = :id');
    $stmt->execute([':id' => $eventId]);

    log_info('Удалено событие', [
        'id' => $eventId,
    ]);

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
}

function handleAutoAssign(PDO $db): void
{
    $month = max(1, min(12, (int) ($_POST['month'] ?? date('n'))));
    $year = (int) ($_POST['year'] ?? date('Y'));
    $force = filter_var($_POST['force'] ?? false, FILTER_VALIDATE_BOOLEAN);

    log_info('Запрошено автоматическое распределение дежурств', [
        'month' => $month,
        'year' => $year,
        'force' => $force,
    ]);

    [$startDate, $endDate] = monthBounds($year, $month);

    $stmt = $db->prepare("SELECT COUNT(*) FROM events WHERE type = 'duty' AND date(start_date) BETWEEN :start AND :end");
    $stmt->execute([':start' => $startDate, ':end' => $endDate]);
    $existingCount = (int) $stmt->fetchColumn();

    if ($existingCount > 0 && !$force) {
        log_info('Автораспределение дежурств требует подтверждения', [
            'existing_duties' => $existingCount,
        ]);
        echo json_encode(['needs_confirm' => true], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($existingCount > 0) {
        $del = $db->prepare("DELETE FROM events WHERE type = 'duty' AND date(start_date) BETWEEN :start AND :end");
        $del->execute([':start' => $startDate, ':end' => $endDate]);
        log_info('Удалены существующие дежурства перед перераспределением', [
            'count' => $existingCount,
        ]);
    }

    $participants = fetchParticipants($db);
    if (empty($participants)) {
        log_warning('Автораспределение не выполнено — нет участников');
        echo json_encode(['message' => 'Нет участников для распределения'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $countsStmt = $db->prepare(
        "SELECT participant_id, strftime('%w', start_date) AS weekday, COUNT(*) AS cnt
         FROM events
         WHERE type = 'duty' AND strftime('%Y', start_date) = :year
         GROUP BY participant_id, weekday"
    );
    $countsStmt->execute([':year' => sprintf('%04d', $year)]);
    $weekdayCounts = [];
    foreach ($participants as $participant) {
        $weekdayCounts[$participant['id']] = array_fill(0, 7, 0);
    }
    while ($row = $countsStmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int) $row['participant_id'];
        $weekdayCounts[$pid][(int) $row['weekday']] = (int) $row['cnt'];
    }

    $totalCounts = [];
    foreach ($weekdayCounts as $pid => $counts) {
        $totalCounts[$pid] = array_sum($counts);
    }

    $eventsStmt = $db->prepare(
        'SELECT * FROM events WHERE type <> :duty AND NOT (date(end_date) < :start OR date(start_date) > :end)'
    );
    $eventsStmt->execute([
        ':duty' => 'duty',
        ':start' => $startDate,
        ':end' => $endDate,
    ]);
    $blocking = [];
    while ($event = $eventsStmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int) $event['participant_id'];
        if (!isset($blocking[$pid])) {
            $blocking[$pid] = [];
        }
        $start = new DateTime($event['start_date']);
        $end = new DateTime($event['end_date']);
        for ($cursor = clone $start; $cursor <= $end; $cursor->modify('+1 day')) {
            $dateKey = $cursor->format('Y-m-d');
            if ($dateKey < $startDate || $dateKey > $endDate) {
                continue;
            }
            $blocking[$pid][$dateKey] = $event['type'];
        }
    }

    $lastDutyStmt = $db->prepare(
        "SELECT participant_id, start_date FROM events WHERE type = 'duty' AND date(start_date) < :start ORDER BY date(start_date) DESC LIMIT 1"
    );
    $lastDutyStmt->execute([':start' => $startDate]);
    $lastAssignedParticipant = null;
    $lastAssignedDate = null;
    if ($prev = $lastDutyStmt->fetch(PDO::FETCH_ASSOC)) {
        $lastAssignedParticipant = (int) $prev['participant_id'];
        $lastAssignedDate = $prev['start_date'];
    }

    $daysInMonth = (int) (new DateTimeImmutable($startDate))->format('t');
    $insertStmt = $db->prepare('INSERT INTO events (participant_id, type, start_date, end_date) VALUES (:pid, :type, :start, :end)');
    $createdEvents = [];
    $skippedDays = [];

    for ($day = 1; $day <= $daysInMonth; $day++) {
        $dateObj = DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $year, $month, $day));
        if ($dateObj === false) {
            continue;
        }
        $dateKey = $dateObj->format('Y-m-d');
        $weekday = (int) $dateObj->format('w');

        $available = [];
        foreach ($participants as $participant) {
            $pid = (int) $participant['id'];
            if (isset($blocking[$pid][$dateKey])) {
                continue;
            }
            $available[] = $pid;
        }

        if (empty($available)) {
            $skippedDays[] = $dateKey;
            continue;
        }

        $candidates = $available;
        if ($lastAssignedParticipant !== null && $lastAssignedDate !== null && areConsecutiveDates($lastAssignedDate, $dateKey)) {
            $candidates = array_values(array_filter(
                $candidates,
                static fn($pid) => $pid !== $lastAssignedParticipant
            ));
        }

        if (empty($candidates)) {
            $skippedDays[] = $dateKey;
            continue;
        }

        usort($candidates, function ($a, $b) use ($weekdayCounts, $weekday, $totalCounts) {
            $weekdayDiff = $weekdayCounts[$a][$weekday] <=> $weekdayCounts[$b][$weekday];
            if ($weekdayDiff !== 0) {
                return $weekdayDiff;
            }
            $totalDiff = $totalCounts[$a] <=> $totalCounts[$b];
            if ($totalDiff !== 0) {
                return $totalDiff;
            }
            return mt_rand(-1, 1);
        });

        $selected = $candidates[0];
        $insertStmt->execute([
            ':pid' => $selected,
            ':type' => 'duty',
            ':start' => $dateKey,
            ':end' => $dateKey,
        ]);
        $eventId = (int) $db->lastInsertId();
        $createdEvents[] = [
            'id' => $eventId,
            'participant_id' => $selected,
            'type' => 'duty',
            'start_date' => $dateKey,
            'end_date' => $dateKey,
        ];
        $weekdayCounts[$selected][$weekday]++;
        $totalCounts[$selected]++;
        $lastAssignedParticipant = $selected;
        $lastAssignedDate = $dateKey;
    }

    $response = ['events' => $createdEvents];
    if (!empty($skippedDays)) {
        $response['skipped'] = $skippedDays;
    }

    log_info('Автораспределение завершено', [
        'created' => count($createdEvents),
        'skipped' => $skippedDays,
    ]);

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
}

function handleClearMonthDuties(PDO $db): void
{
    $month = max(1, min(12, (int) ($_POST['month'] ?? date('n'))));
    $year = (int) ($_POST['year'] ?? date('Y'));
    [$startDate, $endDate] = monthBounds($year, $month);

    log_info('Запрошено удаление дежурств за месяц', [
        'month' => $month,
        'year' => $year,
    ]);

    $countStmt = $db->prepare(
        "SELECT COUNT(*) FROM events WHERE type = 'duty' AND date(start_date) BETWEEN :start AND :end"
    );
    $countStmt->execute([
        ':start' => $startDate,
        ':end' => $endDate,
    ]);
    $existing = (int) $countStmt->fetchColumn();

    if ($existing === 0) {
        log_info('Дежурства для удаления не найдены', [
            'month' => $month,
            'year' => $year,
        ]);
        echo json_encode(['cleared' => 0], JSON_UNESCAPED_UNICODE);
        return;
    }

    $deleteStmt = $db->prepare(
        "DELETE FROM events WHERE type = 'duty' AND date(start_date) BETWEEN :start AND :end"
    );
    $deleteStmt->execute([
        ':start' => $startDate,
        ':end' => $endDate,
    ]);

    log_info('Удалены дежурства за месяц', [
        'month' => $month,
        'year' => $year,
        'removed' => $existing,
    ]);

    echo json_encode(['cleared' => $existing], JSON_UNESCAPED_UNICODE);
}

function handleGetStatistics(PDO $db): void
{
    $year = (int) ($_POST['year'] ?? date('Y'));
    $yearString = sprintf('%04d', $year);
    $participants = fetchParticipants($db);

    log_info('Запрошена статистика', [
        'year' => $year,
    ]);

    $weekdayData = [];
    foreach ($participants as $participant) {
        $weekdayData[$participant['id']] = array_fill(0, 7, 0);
    }

    $stmt = $db->prepare(
        "SELECT participant_id, strftime('%w', start_date) AS weekday, COUNT(*) AS cnt
         FROM events
         WHERE type = 'duty' AND strftime('%Y', start_date) = :year
         GROUP BY participant_id, weekday"
    );
    $stmt->execute([':year' => $yearString]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int) $row['participant_id'];
        if (!isset($weekdayData[$pid])) {
            $weekdayData[$pid] = array_fill(0, 7, 0);
        }
        $weekdayData[$pid][(int) $row['weekday']] = (int) $row['cnt'];
    }

    $rangeStart = sprintf('%04d-01-01', $year);
    $rangeEnd = sprintf('%04d-12-31', $year);
    $stmt = $db->prepare(
        "SELECT participant_id, type, start_date, end_date
         FROM events
         WHERE type IN ('vacation', 'sick')
         AND NOT (date(end_date) < :start OR date(start_date) > :end)"
    );
    $stmt->execute([':start' => $rangeStart, ':end' => $rangeEnd]);

    $extraData = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int) $row['participant_id'];
        if (!isset($extraData[$pid])) {
            $extraData[$pid] = ['vacation' => 0, 'sick' => 0];
        }
        $type = $row['type'];
        $start = new DateTime(max($row['start_date'], $rangeStart));
        $end = new DateTime(min($row['end_date'], $rangeEnd));
        $days = (int) $end->diff($start)->format('%a') + 1;
        if ($days < 0) {
            $days = 0;
        }
        if ($type === 'vacation') {
            $extraData[$pid]['vacation'] += $days;
        } elseif ($type === 'sick') {
            $extraData[$pid]['sick'] += $days;
        }
    }

    $yearsStmt = $db->query("SELECT DISTINCT strftime('%Y', start_date) AS y FROM events ORDER BY y DESC");
    $years = [];
    while ($row = $yearsStmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['y'] !== null) {
            $years[] = (int) $row['y'];
        }
    }
    if (!in_array($year, $years, true)) {
        $years[] = $year;
    }
    rsort($years);

    $result = [];
    foreach ($participants as $participant) {
        $pid = (int) $participant['id'];
        $weekdays = $weekdayData[$pid] ?? array_fill(0, 7, 0);
        $total = array_sum($weekdays);
        $vacation = $extraData[$pid]['vacation'] ?? 0;
        $sick = $extraData[$pid]['sick'] ?? 0;
        $result[] = [
            'id' => $pid,
            'name' => $participant['name'],
            'weekdays' => $weekdays,
            'total' => $total,
            'vacation' => $vacation,
            'sick' => $sick,
        ];
    }

    echo json_encode([
        'year' => $year,
        'years' => array_values(array_unique($years)),
        'data' => $result,
    ], JSON_UNESCAPED_UNICODE);
}

function handleGenerateReport(PDO $db): void
{
    $month = max(1, min(12, (int) ($_POST['month'] ?? date('n'))));
    $year = (int) ($_POST['year'] ?? date('Y'));

    [$startDate, $endDate] = monthBounds($year, $month);
    $participants = fetchParticipants($db);

    log_info('Формирование отчета за месяц', [
        'month' => $month,
        'year' => $year,
        'participants' => count($participants),
    ]);

    if (empty($participants)) {
        http_response_code(422);
        echo json_encode(['error' => 'Нет участников для формирования отчета'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);

    $stmt = $db->prepare(
        "SELECT participant_id, type, start_date, end_date FROM events WHERE type IN ('duty', 'vacation') AND NOT (date(end_date) < :start OR date(start_date) > :end)"
    );
    $stmt->execute([
        ':start' => $startDate,
        ':end' => $endDate,
    ]);

    $grid = [];
    while ($event = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int) $event['participant_id'];
        if (!isset($grid[$pid])) {
            $grid[$pid] = [];
        }

        $start = new DateTimeImmutable($event['start_date']);
        $end = new DateTimeImmutable($event['end_date']);
        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
            $current = $cursor->format('Y-m-d');
            if ($current < $startDate || $current > $endDate) {
                continue;
            }

            if ($event['type'] === 'vacation') {
                if (!isset($grid[$pid][$current])) {
                    $grid[$pid][$current] = 'Отпуск';
                }
            } elseif ($event['type'] === 'duty') {
                $grid[$pid][$current] = 'Х';
            }
        }
    }

    $headers = ['ФИО'];
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $headers[] = (string) $day;
    }

    $rows = [];
    foreach ($participants as $participant) {
        $pid = (int) $participant['id'];
        $row = [$participant['name']];
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $dateKey = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $row[] = $grid[$pid][$dateKey] ?? '';
        }
        $rows[] = $row;
    }

    if (!class_exists('ZipArchive')) {
        log_error('Формирование отчета невозможно — отсутствует ZipArchive');
        http_response_code(500);
        echo json_encode(['error' => 'ZipArchive недоступен на сервере'], JSON_UNESCAPED_UNICODE);
        return;
    }

    try {
        $filePath = createDutyReportDocx($headers, $rows, $year, $month);
    } catch (Throwable $e) {
        log_error('Не удалось сформировать DOCX-отчет', [
            'error' => $e->getMessage(),
        ]);
        http_response_code(500);
        echo json_encode(['error' => 'Не удалось сформировать отчет'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $fileName = sprintf('duty-report-%04d-%02d.docx', $year, $month);
    $fileSize = @filesize($filePath) ?: null;

    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    if ($fileSize !== null) {
        header('Content-Length: ' . $fileSize);
    }

    readfile($filePath);
    unlink($filePath);

    log_info('DOCX-отчет сформирован и отправлен', [
        'file' => $fileName,
    ]);

    exit;
}

function fetchParticipants(PDO $db): array
{
    $stmt = $db->query('SELECT id, name FROM participants ORDER BY sort_order, id');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function monthBounds(int $year, int $month): array
{
    $start = sprintf('%04d-%02d-01', $year, $month);
    $days = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $end = sprintf('%04d-%02d-%02d', $year, $month, $days);
    return [$start, $end];
}

function validateDate(string $value): bool
{
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
}

function areConsecutiveDates(string $previous, string $current): bool
{
    $prev = DateTimeImmutable::createFromFormat('Y-m-d', $previous);
    $curr = DateTimeImmutable::createFromFormat('Y-m-d', $current);
    if (!$prev || !$curr) {
        return false;
    }

    return $prev->modify('+1 day')->format('Y-m-d') === $curr->format('Y-m-d');
}

function createDutyReportDocx(array $headers, array $rows, int $year, int $month): string
{
    $title = sprintf('График дежурств — %s %d', monthNameRu($month), $year);
    $documentXml = buildReportDocumentXml($title, $headers, $rows);

    $tmpFile = tempnam(sys_get_temp_dir(), 'duty_report_');
    if ($tmpFile === false) {
        throw new RuntimeException('Не удалось создать временный файл для отчета');
    }

    $zip = new ZipArchive();
    $opened = $zip->open($tmpFile, ZipArchive::OVERWRITE);
    if ($opened !== true) {
        $opened = $zip->open($tmpFile, ZipArchive::CREATE);
    }

    if ($opened !== true) {
        @unlink($tmpFile);
        throw new RuntimeException('Не удалось открыть архив для отчета');
    }

    $zip->addFromString('[Content_Types].xml', getDocxContentTypesXml());
    $zip->addFromString('_rels/.rels', getDocxRelsXml());
    $zip->addFromString('word/document.xml', $documentXml);
    $zip->addFromString('word/styles.xml', getDocxStylesXml());
    $zip->addFromString('word/_rels/document.xml.rels', getDocxDocumentRelsXml());
    $zip->close();

    return $tmpFile;
}

function buildReportDocumentXml(string $title, array $headers, array $rows): string
{
    $table = '<w:tbl>'
        . '<w:tblPr>'
        . '<w:tblStyle w:val="TableGrid"/>'
        . '<w:tblW w:w="0" w:type="auto"/>'
        . '<w:tblBorders>'
        . '<w:top w:val="single" w:sz="8" w:space="0" w:color="auto"/>'
        . '<w:left w:val="single" w:sz="8" w:space="0" w:color="auto"/>'
        . '<w:bottom w:val="single" w:sz="8" w:space="0" w:color="auto"/>'
        . '<w:right w:val="single" w:sz="8" w:space="0" w:color="auto"/>'
        . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
        . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
        . '</w:tblBorders>'
        . '</w:tblPr>'
        . '<w:tblGrid>';

    foreach ($headers as $_) {
        $table .= '<w:gridCol w:w="0"/>';
    }
    $table .= '</w:tblGrid>';

    $table .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>';
    foreach ($headers as $index => $headerText) {
        $alignment = $index === 0 ? 'left' : 'center';
        $table .= buildTableCellXml((string) $headerText, true, $alignment, true);
    }
    $table .= '</w:tr>';

    foreach ($rows as $row) {
        $table .= '<w:tr>';
        foreach ($row as $index => $cellText) {
            $alignment = $index === 0 ? 'left' : 'center';
            $table .= buildTableCellXml((string) $cellText, false, $alignment, false);
        }
        $table .= '</w:tr>';
    }

    $table .= '</w:tbl>';

    $body = '<w:body>'
        . '<w:p>'
        . '<w:pPr><w:jc w:val="center"/></w:pPr>'
        . '<w:r>'
        . '<w:rPr><w:b/><w:sz w:val="32"/><w:szCs w:val="32"/></w:rPr>'
        . '<w:t xml:space="preserve">' . docxEscape($title) . '</w:t>'
        . '</w:r>'
        . '</w:p>'
        . $table
        . '<w:sectPr>'
        . '<w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/>'
        . '<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="708" w:footer="708" w:gutter="0"/>'
        . '<w:cols w:space="708"/>'
        . '<w:docGrid w:linePitch="360"/>'
        . '</w:sectPr>'
        . '</w:body>';

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
        . '>'
        . $body
        . '</w:document>';
}

function buildTableCellXml(string $text, bool $bold, ?string $alignment, bool $highlightHeader): string
{
    $tcPr = '';
    if ($highlightHeader) {
        $tcPr = '<w:tcPr>'
            . '<w:shd w:val="clear" w:color="auto" w:fill="E2E8F0"/>'
            . '</w:tcPr>';
    }

    $paragraph = '';
    if ($alignment !== null) {
        $paragraph .= '<w:pPr><w:jc w:val="' . $alignment . '"/></w:pPr>';
    }

    $run = '<w:r>';
    if ($bold) {
        $run .= '<w:rPr><w:b/><w:bCs/></w:rPr>';
    }
    $escaped = docxEscape($text);
    if ($escaped === '') {
        $run .= '<w:t/>';
    } else {
        $run .= '<w:t xml:space="preserve">' . $escaped . '</w:t>';
    }
    $run .= '</w:r>';

    return '<w:tc>' . $tcPr . '<w:p>' . $paragraph . $run . '</w:p></w:tc>';
}

function docxEscape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function getDocxContentTypesXml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        . '</Types>';
}

function getDocxRelsXml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>';
}

function getDocxDocumentRelsXml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>';
}

function getDocxStylesXml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal">'
        . '<w:name w:val="Normal"/>'
        . '<w:qFormat/>'
        . '</w:style>'
        . '<w:style w:type="table" w:styleId="TableGrid">'
        . '<w:name w:val="Table Grid"/>'
        . '<w:basedOn w:val="TableNormal"/>'
        . '<w:uiPriority w:val="59"/>'
        . '<w:tblPr>'
        . '<w:tblBorders>'
        . '<w:top w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
        . '<w:left w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
        . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
        . '<w:right w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
        . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
        . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
        . '</w:tblBorders>'
        . '</w:tblPr>'
        . '</w:style>'
        . '</w:styles>';
}

function monthNameRu(int $month): string
{
    $months = [
        1 => 'Январь',
        2 => 'Февраль',
        3 => 'Март',
        4 => 'Апрель',
        5 => 'Май',
        6 => 'Июнь',
        7 => 'Июль',
        8 => 'Август',
        9 => 'Сентябрь',
        10 => 'Октябрь',
        11 => 'Ноябрь',
        12 => 'Декабрь',
    ];

    return $months[$month] ?? '';
}
