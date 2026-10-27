<?php
/**
 * Class schedules.
 *
 * Administrators manage every schedule. Professors can open the same page but
 * only see their own assigned classes, read-only.
 *
 * Overlaps are rejected for the same professor, the same section, and the same
 * room. Reactivating an inactive schedule re-runs the same checks because the
 * timetable may have changed since it was deactivated.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

require_role(['Admin', 'Professor']);

$role = current_role();
$isAdmin = $role === 'Admin';
$professorId = null;

if (!$isAdmin) {
    $stmt = $conn->prepare(
        "SELECT professor_id FROM users WHERE id = ? AND role = 'Professor' LIMIT 1"
    );
    $userId = current_user_id();
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $professorId = (string) ($stmt->get_result()->fetch_assoc()['professor_id'] ?? '');
    $stmt->close();

    if ($professorId === '') {
        abort(403, 'Your account is not linked to a professor profile. Ask an administrator to link it.');
    }
}

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

/** Fetch one schedule row by primary key. */
function find_schedule(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare('SELECT * FROM schedules WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/**
 * Validate a schedule payload.
 *
 * @return array<int,string>
 */
function schedule_errors(
    mysqli $conn,
    string $courseCode,
    string $professorId,
    string $section,
    string $room,
    string $day,
    string $startTime,
    string $endTime,
    int $excludeId = 0
): array {
    $errors = [];

    if (!active_course_exists($conn, $courseCode)) {
        $errors[] = 'Choose an active course.';
    }
    if (!active_professor_exists($conn, $professorId)) {
        $errors[] = 'Choose an active professor.';
    }
    if ($section === '' || mb_strlen($section) > 50) {
        $errors[] = 'Enter the section (maximum 50 characters).';
    }
    if (mb_strlen($room) > 50) {
        $errors[] = 'The room name is too long (maximum 50 characters).';
    }
    if (!in_array($day, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], true)) {
        $errors[] = 'Choose a valid day.';
    }
    if (!valid_time($startTime) || !valid_time($endTime)) {
        $errors[] = 'Enter valid start and end times.';
    } elseif (normalize_time($endTime) <= normalize_time($startTime)) {
        $errors[] = 'The end time must be later than the start time.';
    }

    if ($errors === [] && schedule_conflict_exists(
        $conn,
        $day,
        normalize_time($startTime),
        normalize_time($endTime),
        $section,
        $professorId,
        $excludeId
    )) {
        $errors[] = 'That professor or section already has an overlapping class on ' . $day . '.';
    }

    if ($errors === [] && room_conflict_exists(
        $conn,
        $day,
        normalize_time($startTime),
        normalize_time($endTime),
        $room,
        $excludeId
    )) {
        $errors[] = 'That room is already booked for an overlapping class on ' . $day . '.';
    }

    return $errors;
}

/** Shared sanitising of the schedule form. */
function schedule_input(): array
{
    return [
        'course_code' => post_string('course_code'),
        'professor_id' => post_string('professor_id'),
        'section' => mb_substr(post_string('section'), 0, 50),
        'room' => mb_substr(post_string('room'), 0, 50),
        'day' => post_string('day'),
        'start_time' => post_string('start_time'),
        'end_time' => post_string('end_time'),
    ];
}

if (is_post()) {
    csrf_verify();

    if (!$isAdmin) {
        abort(403, 'Only administrators can change schedules.');
    }

    $action = post_string('action');

    if ($action === 'add_schedule') {
        $input = schedule_input();
        $errors = schedule_errors(
            $conn,
            $input['course_code'],
            $input['professor_id'],
            $input['section'],
            $input['room'],
            $input['day'],
            $input['start_time'],
            $input['end_time']
        );

        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect('schedules.php');
        }

        try {
            $startTimeValue = normalize_time($input['start_time']);
            $endTimeValue = normalize_time($input['end_time']);

            $stmt = $conn->prepare(
                "INSERT INTO schedules
                    (course_code, professor_id, section, room, day, start_time, end_time, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'Active')"
            );
            $stmt->bind_param(
                'sssssss',
                $input['course_code'],
                $input['professor_id'],
                $input['section'],
                $input['room'],
                $input['day'],
                $startTimeValue,
                $endTimeValue
            );
            $stmt->execute();
            $stmt->close();

            flash('success', 'Schedule added successfully.');
        } catch (Throwable $e) {
            error_log('Add schedule failed: ' . $e->getMessage());
            flash('danger', 'Unable to add the schedule. Please try again.');
        }

        redirect('schedules.php');
    }

    if ($action === 'update_schedule') {
        $id = post_int('id');
        $schedule = find_schedule($conn, $id);

        if ($schedule === null) {
            flash('danger', 'Schedule not found.');
            redirect('schedules.php');
        }

        $input = schedule_input();
        $errors = schedule_errors(
            $conn,
            $input['course_code'],
            $input['professor_id'],
            $input['section'],
            $input['room'],
            $input['day'],
            $input['start_time'],
            $input['end_time'],
            $id
        );

        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect('schedules.php');
        }

        try {
            $startTimeValue = normalize_time($input['start_time']);
            $endTimeValue = normalize_time($input['end_time']);

            $stmt = $conn->prepare(
                'UPDATE schedules
                 SET course_code = ?, professor_id = ?, section = ?, room = ?, day = ?, start_time = ?, end_time = ?
                 WHERE id = ?'
            );
            $stmt->bind_param(
                'sssssssi',
                $input['course_code'],
                $input['professor_id'],
                $input['section'],
                $input['room'],
                $input['day'],
                $startTimeValue,
                $endTimeValue,
                $schedule['id']
            );
            $stmt->execute();
            $stmt->close();

            flash('success', 'Schedule updated successfully.');
        } catch (Throwable $e) {
            error_log('Update schedule failed: ' . $e->getMessage());
            flash('danger', 'Unable to update the schedule. Please try again.');
        }

        redirect('schedules.php');
    }

    if ($action === 'set_status') {
        $id = post_int('id');
        $status = post_string('status');
        $schedule = find_schedule($conn, $id);

        if ($schedule === null) {
            flash('danger', 'Schedule not found.');
            redirect('schedules.php');
        }

        if (!in_list($status, ['Active', 'Inactive'])) {
            flash('danger', 'Invalid status.');
            redirect('schedules.php');
        }

        if ($status === 'Active') {
            $errors = schedule_errors(
                $conn,
                $schedule['course_code'],
                $schedule['professor_id'],
                $schedule['section'],
                $schedule['room'],
                $schedule['day'],
                $schedule['start_time'],
                $schedule['end_time'],
                $id
            );

            if ($errors !== []) {
                flash('danger', 'This schedule can no longer be activated. ' . implode(' ', $errors));
                redirect('schedules.php');
            }
        }

        try {
            $stmt = $conn->prepare('UPDATE schedules SET status = ? WHERE id = ?');
            $stmt->bind_param('si', $status, $id);
            $stmt->execute();
            $stmt->close();

            flash('success', $status === 'Active' ? 'Schedule activated.' : 'Schedule deactivated.');
        } catch (Throwable $e) {
            error_log('Schedule status change failed: ' . $e->getMessage());
            flash('danger', 'Unable to change the schedule status. Please try again.');
        }

        redirect('schedules.php');
    }

    flash('danger', 'Unknown action.');
    redirect('schedules.php');
}

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------

$search = mb_substr(query_string('search'), 0, 100);
$statusFilter = query_string('status', 'All');

if (!in_list($statusFilter, ['All', 'Active', 'Inactive'])) {
    $statusFilter = 'All';
}

$where = [];
$types = '';
$params = [];

if (!$isAdmin) {
    $where[] = 's.professor_id = ?';
    $types .= 's';
    $params[] = (string) $professorId;
}

if ($statusFilter !== 'All') {
    $where[] = 's.status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}

if ($search !== '') {
    $where[] = '(s.course_code LIKE ? OR c.course_name LIKE ? OR s.professor_id LIKE ? OR p.fullname LIKE ? OR s.section LIKE ? OR s.room LIKE ? OR s.day LIKE ?)';
    $types .= 'sssssss';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term, $term, $term, $term);
}

$sql = "SELECT s.*, c.course_name, p.fullname AS professor_name
        FROM schedules s
        LEFT JOIN courses c ON c.course_code = s.course_code
        LEFT JOIN professors p ON p.professor_id = s.professor_id";

if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= " ORDER BY FIELD(s.day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'), s.start_time, s.course_code";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$schedules = [];
while ($row = $result->fetch_assoc()) {
    $schedules[] = $row;
}
$stmt->close();

$courses = [];
$professors = [];

if ($isAdmin) {
    $courseResult = $conn->query(
        "SELECT course_code, course_name, status FROM courses ORDER BY course_code"
    );
    while ($row = $courseResult->fetch_assoc()) {
        $courses[] = $row;
    }

    $professorResult = $conn->query(
        "SELECT professor_id, fullname, status FROM professors ORDER BY fullname"
    );
    while ($row = $professorResult->fetch_assoc()) {
        $professors[] = $row;
    }
}

$pageTitle = $isAdmin ? 'Schedules' : 'My Schedule';
$pageSubtitle = $isAdmin
    ? 'Class times, rooms, and teaching assignments'
    : 'The classes assigned to you';
$active = $isAdmin ? 'schedules' : 'my_schedule';
$pageActions = $isAdmin
    ? '<button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addSchedule">'
        . '<i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add schedule</button>'
    : '';
require __DIR__ . '/inc/header.php';
?>

<form class="toolbar" method="get" action="schedules.php">
    <div class="search-box">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <label class="visually-hidden" for="schedule-search">Search schedules</label>
        <input type="search" class="form-control" id="schedule-search" name="search"
               value="<?= e($search) ?>"
               placeholder="<?= $isAdmin ? 'Search course, professor, section, room, or day' : 'Search course, section, room, or day' ?>">
    </div>

    <label class="visually-hidden" for="schedule-status">Filter by status</label>
    <select class="form-select" id="schedule-status" name="status">
        <option value="All" <?= selected($statusFilter, 'All') ?>>All statuses</option>
        <option value="Active" <?= selected($statusFilter, 'Active') ?>>Active</option>
        <option value="Inactive" <?= selected($statusFilter, 'Inactive') ?>>Inactive</option>
    </select>

    <button class="btn btn-primary" type="submit">
        <i class="fa-solid fa-filter me-1" aria-hidden="true"></i>Filter
    </button>

    <?php if ($search !== '' || $statusFilter !== 'All'): ?>
        <a class="btn btn-outline-secondary" href="schedules.php">Reset</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th scope="col">Course</th>
                    <?php if ($isAdmin): ?>
                        <th scope="col">Professor</th>
                    <?php endif; ?>
                    <th scope="col">Section</th>
                    <th scope="col">Room</th>
                    <th scope="col">Day</th>
                    <th scope="col">Time</th>
                    <th scope="col">Status</th>
                    <?php if ($isAdmin): ?>
                        <th scope="col" class="text-end">Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($schedules === []): ?>
                    <tr>
                        <td colspan="<?= $isAdmin ? 8 : 6 ?>">
                            <div class="empty-state">
                                <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
                                <h5>No schedules found</h5>
                                <p><?= $isAdmin ? 'Try a different search or filter.' : 'You have no classes assigned yet.' ?></p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($schedules as $schedule): ?>
                    <tr>
                        <td>
                            <strong><?= e($schedule['course_code']) ?></strong>
                            <div class="text-muted small"><?= e($schedule['course_name'] ?? '') ?></div>
                        </td>
                        <?php if ($isAdmin): ?>
                            <td>
                                <?= e($schedule['professor_name'] ?? '') !== '' ? e($schedule['professor_name']) : '<span class="text-muted">—</span>' ?>
                                <div class="text-muted small mono"><?= e($schedule['professor_id']) ?></div>
                            </td>
                        <?php endif; ?>
                        <td><?= e($schedule['section']) ?></td>
                        <td><?= e($schedule['room'] ?? '') !== '' ? e($schedule['room']) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= e($schedule['day']) ?></td>
                        <td class="nowrap">
                            <?= e(format_time($schedule['start_time'])) ?>
                            &ndash;
                            <?= e(format_time($schedule['end_time'])) ?>
                        </td>
                        <td>
                            <span class="badge text-bg-<?= e(status_badge_class($schedule['status'])) ?>">
                                <?= e($schedule['status']) ?>
                            </span>
                        </td>
                        <?php if ($isAdmin): ?>
                            <td>
                                <div class="actions justify-content-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editSchedule<?= (int) $schedule['id'] ?>">
                                        Edit
                                    </button>

                                    <?php if ($schedule['status'] === 'Active'): ?>
                                        <form method="post" data-confirm="Deactivate this schedule?">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="set_status">
                                            <input type="hidden" name="id" value="<?= (int) $schedule['id'] ?>">
                                            <input type="hidden" name="status" value="Inactive">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="set_status">
                                            <input type="hidden" name="id" value="<?= (int) $schedule['id'] ?>">
                                            <input type="hidden" name="status" value="Active">
                                            <button type="submit" class="btn btn-sm btn-outline-success">Activate</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add schedule -->
<?php if ($isAdmin): ?>
<div class="modal fade" id="addSchedule" tabindex="-1" aria-labelledby="addScheduleLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_schedule">

                <div class="modal-header">
                    <h2 class="modal-title h5" id="addScheduleLabel">Add schedule</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="add-schedule-course">Course</label>
                            <select class="form-select" id="add-schedule-course" name="course_code" required>
                                <option value="">Select course</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?= e($course['course_code']) ?>" <?= $course['status'] !== 'Active' ? 'disabled' : '' ?>>
                                        <?= e($course['course_code']) ?> &middot; <?= e($course['course_name']) ?>
                                        <?= $course['status'] !== 'Active' ? ' (inactive)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-schedule-professor">Professor</label>
                            <select class="form-select" id="add-schedule-professor" name="professor_id" required>
                                <option value="">Select professor</option>
                                <?php foreach ($professors as $professor): ?>
                                    <option value="<?= e($professor['professor_id']) ?>" <?= $professor['status'] !== 'Active' ? 'disabled' : '' ?>>
                                        <?= e($professor['fullname']) ?> (<?= e($professor['professor_id']) ?>)
                                        <?= $professor['status'] !== 'Active' ? ' — inactive' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-schedule-section">Section</label>
                            <input type="text" class="form-control" id="add-schedule-section" name="section"
                                   maxlength="50" placeholder="e.g. A" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-schedule-room">Room</label>
                            <input type="text" class="form-control" id="add-schedule-room" name="room"
                                   maxlength="50" placeholder="e.g. Room 201">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="add-schedule-day">Day</label>
                            <select class="form-select" id="add-schedule-day" name="day" required>
                                <option value="">Select day</option>
                                <?php foreach ($days as $day): ?>
                                    <option value="<?= e($day) ?>"><?= e($day) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="add-schedule-start">Start time</label>
                            <input type="time" class="form-control" id="add-schedule-start" name="start_time" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="add-schedule-end">End time</label>
                            <input type="time" class="form-control" id="add-schedule-end" name="end_time" required>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add schedule</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<?php foreach ($schedules as $schedule): ?>
    <?php $scheduleId = (int) $schedule['id']; ?>

    <div class="modal fade" id="editSchedule<?= $scheduleId ?>" tabindex="-1"
         aria-labelledby="editScheduleLabel<?= $scheduleId ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_schedule">
                    <input type="hidden" name="id" value="<?= $scheduleId ?>">

                    <div class="modal-header">
                        <h2 class="modal-title h5" id="editScheduleLabel<?= $scheduleId ?>">
                            Edit <?= e($schedule['course_code']) ?> &middot; <?= e($schedule['section']) ?>
                        </h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="edit-schedule-course<?= $scheduleId ?>">Course</label>
                                <select class="form-select" id="edit-schedule-course<?= $scheduleId ?>" name="course_code" required>
                                    <?php foreach ($courses as $course): ?>
                                        <option value="<?= e($course['course_code']) ?>"
                                            <?= selected($schedule['course_code'], $course['course_code']) ?>
                                            <?= $course['status'] !== 'Active' && $schedule['course_code'] !== $course['course_code'] ? 'disabled' : '' ?>>
                                            <?= e($course['course_code']) ?> &middot; <?= e($course['course_name']) ?>
                                            <?= $course['status'] !== 'Active' ? ' (inactive)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-schedule-professor<?= $scheduleId ?>">Professor</label>
                                <select class="form-select" id="edit-schedule-professor<?= $scheduleId ?>" name="professor_id" required>
                                    <?php foreach ($professors as $professor): ?>
                                        <option value="<?= e($professor['professor_id']) ?>"
                                            <?= selected($schedule['professor_id'], $professor['professor_id']) ?>
                                            <?= $professor['status'] !== 'Active' && $schedule['professor_id'] !== $professor['professor_id'] ? 'disabled' : '' ?>>
                                            <?= e($professor['fullname']) ?> (<?= e($professor['professor_id']) ?>)
                                            <?= $professor['status'] !== 'Active' ? ' — inactive' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-schedule-section<?= $scheduleId ?>">Section</label>
                                <input type="text" class="form-control" id="edit-schedule-section<?= $scheduleId ?>"
                                       name="section" value="<?= e($schedule['section']) ?>" maxlength="50" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-schedule-room<?= $scheduleId ?>">Room</label>
                                <input type="text" class="form-control" id="edit-schedule-room<?= $scheduleId ?>"
                                       name="room" value="<?= e($schedule['room'] ?? '') ?>" maxlength="50">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label" for="edit-schedule-day<?= $scheduleId ?>">Day</label>
                                <select class="form-select" id="edit-schedule-day<?= $scheduleId ?>" name="day" required>
                                    <?php foreach ($days as $day): ?>
                                        <option value="<?= e($day) ?>" <?= selected($schedule['day'], $day) ?>>
                                            <?= e($day) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label" for="edit-schedule-start<?= $scheduleId ?>">Start time</label>
                                <input type="time" class="form-control" id="edit-schedule-start<?= $scheduleId ?>"
                                       name="start_time" value="<?= e(substr((string) $schedule['start_time'], 0, 5)) ?>" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label" for="edit-schedule-end<?= $scheduleId ?>">End time</label>
                                <input type="time" class="form-control" id="edit-schedule-end<?= $scheduleId ?>"
                                       name="end_time" value="<?= e(substr((string) $schedule['end_time'], 0, 5)) ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/inc/footer.php'; ?>
