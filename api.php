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
        case 'get_statistics':
            handleGetStatistics($db);
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

        usort($available, function ($a, $b) use ($weekdayCounts, $weekday, $totalCounts) {
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

        $selected = $available[0];
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
