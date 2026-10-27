<?php
/**
 * Professor attendance log (Admin only).
 *
 * Tracks faculty time in/out per day. A professor can only have one entry per
 * date, and time out must be later than time in when both are supplied.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

require_role('Admin');

$statusOptions = ['Present', 'Absent', 'Late'];

/** Fetch one professor attendance row by primary key. */
function find_professor_attendance(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare('SELECT * FROM professor_attendance WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/** Has this professor already got an entry for the date? */
function professor_attendance_duplicate(
    mysqli $conn,
    string $professorId,
    string $date,
    int $excludeId = 0
): bool {
    $sql = 'SELECT id FROM professor_attendance WHERE professor_id = ? AND attendance_date = ?';
    $types = 'ss';
    $params = [$professorId, $date];

    if ($excludeId > 0) {
        $sql .= ' AND id <> ?';
        $types .= 'i';
        $params[] = $excludeId;
    }

    $sql .= ' LIMIT 1';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $exists;
}

if (is_post()) {
    csrf_verify();

    $action = post_string('action');

    if ($action === 'add_attendance' || $action === 'update_attendance') {
        $isUpdate = $action === 'update_attendance';
        $id = $isUpdate ? post_int('id') : 0;
        $record = $isUpdate ? find_professor_attendance($conn, $id) : null;

        if ($isUpdate && $record === null) {
            flash('danger', 'Professor attendance record not found.');
            redirect('professor_attendance.php');
        }

        $professorId = post_string('professor_id');
        $date = post_string('attendance_date');
        $timeIn = post_string('time_in');
        $timeOut = post_string('time_out');
        $status = post_string('status');
        $remarks = mb_substr(post_string('remarks'), 0, 255);

        $errors = [];

        $professorUnchanged = $record !== null && $professorId === (string) $record['professor_id'];
        if (!$professorUnchanged && !active_professor_exists($conn, $professorId)) {
            $errors[] = 'Choose an active professor.';
        }
        if (!valid_date($date)) {
            $errors[] = 'Choose a valid date.';
        }
        if (!in_list($status, $statusOptions)) {
            $errors[] = 'Choose a valid attendance status.';
        }

        if ($timeIn !== '' && !valid_time($timeIn)) {
            $errors[] = 'Enter a valid time in.';
        }
        if ($timeOut !== '' && !valid_time($timeOut)) {
            $errors[] = 'Enter a valid time out.';
        }
        if ($errors === []
            && $timeIn !== ''
            && $timeOut !== ''
            && normalize_time($timeOut) <= normalize_time($timeIn)) {
            $errors[] = 'Time out must be later than time in.';
        }

        if ($errors === [] && professor_attendance_duplicate($conn, $professorId, $date, $id)) {
            $errors[] = 'An entry already exists for that professor on ' . format_date($date) . '.';
        }

        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect('professor_attendance.php');
        }

        $timeInValue = $timeIn !== '' ? normalize_time($timeIn) : null;
        $timeOutValue = $timeOut !== '' ? normalize_time($timeOut) : null;
        $remarksValue = $remarks !== '' ? $remarks : null;

        try {
            if ($isUpdate) {
                $stmt = $conn->prepare(
                    'UPDATE professor_attendance
                     SET professor_id = ?, attendance_date = ?, time_in = ?, time_out = ?, status = ?, remarks = ?
                     WHERE id = ?'
                );
                $stmt->bind_param(
                    'ssssssi',
                    $professorId,
                    $date,
                    $timeInValue,
                    $timeOutValue,
                    $status,
                    $remarksValue,
                    $id
                );
                $stmt->execute();
                $stmt->close();

                flash('success', 'Professor attendance updated.');
            } else {
                $stmt = $conn->prepare(
                    'INSERT INTO professor_attendance
                        (professor_id, attendance_date, time_in, time_out, status, remarks)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param(
                    'ssssss',
                    $professorId,
                    $date,
                    $timeInValue,
                    $timeOutValue,
                    $status,
                    $remarksValue
                );
                $stmt->execute();
                $stmt->close();

                flash('success', 'Professor attendance recorded.');
            }
        } catch (Throwable $e) {
            error_log('Professor attendance save failed: ' . $e->getMessage());
            flash('danger', 'Unable to save the record. Please try again.');
        }

        redirect('professor_attendance.php');
    }

    if ($action === 'delete_attendance') {
        $id = post_int('id');
        $record = find_professor_attendance($conn, $id);

        if ($record === null) {
            flash('danger', 'Professor attendance record not found.');
            redirect('professor_attendance.php');
        }

        try {
            $stmt = $conn->prepare('DELETE FROM professor_attendance WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            flash('success', 'Professor attendance record deleted.');
        } catch (Throwable $e) {
            error_log('Professor attendance delete failed: ' . $e->getMessage());
            flash('danger', 'Unable to delete the record. Please try again.');
        }

        redirect('professor_attendance.php');
    }

    flash('danger', 'Unknown action.');
    redirect('professor_attendance.php');
}

// ---------------------------------------------------------------------------
// Filters and rows
// ---------------------------------------------------------------------------

$search = mb_substr(query_string('search'), 0, 100);
$statusFilter = query_string('status', 'All');
$dateFilter = query_string('date');

if (!in_list($statusFilter, array_merge(['All'], $statusOptions))) {
    $statusFilter = 'All';
}
if ($dateFilter !== '' && !valid_date($dateFilter)) {
    $dateFilter = '';
}

$where = [];
$types = '';
$params = [];

if ($statusFilter !== 'All') {
    $where[] = 'pa.status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}

if ($dateFilter !== '') {
    $where[] = 'pa.attendance_date = ?';
    $types .= 's';
    $params[] = $dateFilter;
}

if ($search !== '') {
    $where[] = '(pa.professor_id LIKE ? OR p.fullname LIKE ? OR pa.status LIKE ? OR pa.remarks LIKE ?)';
    $types .= 'ssss';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term);
}

$sql = 'SELECT pa.*, p.fullname
        FROM professor_attendance pa
        LEFT JOIN professors p ON p.professor_id = pa.professor_id';

if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY pa.attendance_date DESC, pa.id DESC';

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$records = [];
while ($row = $result->fetch_assoc()) {
    $records[] = $row;
}
$stmt->close();

$professors = [];
$result = $conn->query(
    "SELECT professor_id, fullname FROM professors WHERE status = 'Active' ORDER BY fullname"
);
while ($row = $result->fetch_assoc()) {
    $professors[] = $row;
}

$pageTitle = 'Professor Attendance';
$pageSubtitle = 'Faculty time-in and time-out logs';
$active = 'professor_attendance';
$pageActions = '<button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addProfessorAttendance">'
    . '<i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Record attendance</button>';
require __DIR__ . '/inc/header.php';
?>

<form class="toolbar" method="get" action="professor_attendance.php">
    <div class="search-box">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <label class="visually-hidden" for="pa-search">Search professor attendance</label>
        <input type="search" class="form-control" id="pa-search" name="search"
               value="<?= e($search) ?>" placeholder="Search professor, status, or remarks">
    </div>

    <label class="visually-hidden" for="pa-date">Filter by date</label>
    <input type="date" class="form-control" id="pa-date" name="date" value="<?= e($dateFilter) ?>"
           style="max-width: 180px;">

    <label class="visually-hidden" for="pa-status">Filter by status</label>
    <select class="form-select" id="pa-status" name="status">
        <option value="All" <?= selected($statusFilter, 'All') ?>>All statuses</option>
        <?php foreach ($statusOptions as $status): ?>
            <option value="<?= e($status) ?>" <?= selected($statusFilter, $status) ?>><?= e($status) ?></option>
        <?php endforeach; ?>
    </select>

    <button class="btn btn-primary" type="submit">
        <i class="fa-solid fa-filter me-1" aria-hidden="true"></i>Filter
    </button>

    <?php if ($search !== '' || $statusFilter !== 'All' || $dateFilter !== ''): ?>
        <a class="btn btn-outline-secondary" href="professor_attendance.php">Reset</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th scope="col">Professor</th>
                    <th scope="col">Date</th>
                    <th scope="col">Time in</th>
                    <th scope="col">Time out</th>
                    <th scope="col">Status</th>
                    <th scope="col">Remarks</th>
                    <th scope="col" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($records === []): ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <i class="fa-solid fa-user-clock" aria-hidden="true"></i>
                                <h5>No professor attendance records</h5>
                                <p>Record attendance or change the filters.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($records as $record): ?>
                    <tr>
                        <td>
                            <strong><?= e($record['fullname'] ?? $record['professor_id']) ?></strong>
                            <div class="text-muted small mono"><?= e($record['professor_id']) ?></div>
                        </td>
                        <td class="nowrap"><?= e(format_date($record['attendance_date'])) ?></td>
                        <td class="nowrap"><?= e(format_time($record['time_in'])) ?></td>
                        <td class="nowrap"><?= e(format_time($record['time_out'])) ?></td>
                        <td>
                            <span class="badge text-bg-<?= e(status_badge_class($record['status'])) ?>">
                                <?= e($record['status']) ?>
                            </span>
                        </td>
                        <td>
                            <?= ($record['remarks'] ?? '') !== '' ? e($record['remarks']) : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td>
                            <div class="actions justify-content-end">
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editProfessorAttendance<?= (int) $record['id'] ?>">
                                    Edit
                                </button>

                                <form method="post" data-confirm="Delete this professor attendance record?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_attendance">
                                    <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Record professor attendance -->
<div class="modal fade" id="addProfessorAttendance" tabindex="-1"
     aria-labelledby="addProfessorAttendanceLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_attendance">

                <div class="modal-header">
                    <h2 class="modal-title h5" id="addProfessorAttendanceLabel">Record professor attendance</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="add-pa-professor">Professor</label>
                            <select class="form-select" id="add-pa-professor" name="professor_id" required>
                                <option value="">Select professor</option>
                                <?php foreach ($professors as $professor): ?>
                                    <option value="<?= e($professor['professor_id']) ?>">
                                        <?= e($professor['fullname']) ?> (<?= e($professor['professor_id']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-pa-date">Date</label>
                            <input type="date" class="form-control" id="add-pa-date" name="attendance_date"
                                   value="<?= e(date('Y-m-d')) ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-pa-time-in">Time in</label>
                            <input type="time" class="form-control" id="add-pa-time-in" name="time_in">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-pa-time-out">Time out</label>
                            <input type="time" class="form-control" id="add-pa-time-out" name="time_out">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-pa-status">Status</label>
                            <select class="form-select" id="add-pa-status" name="status" required>
                                <?php foreach ($statusOptions as $status): ?>
                                    <option value="<?= e($status) ?>"><?= e($status) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-pa-remarks">Remarks</label>
                            <input type="text" class="form-control" id="add-pa-remarks" name="remarks"
                                   maxlength="255" placeholder="Optional">
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save record</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php foreach ($records as $record): ?>
    <?php $recordId = (int) $record['id']; ?>

    <div class="modal fade" id="editProfessorAttendance<?= $recordId ?>" tabindex="-1"
         aria-labelledby="editProfessorAttendanceLabel<?= $recordId ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_attendance">
                    <input type="hidden" name="id" value="<?= $recordId ?>">

                    <div class="modal-header">
                        <h2 class="modal-title h5" id="editProfessorAttendanceLabel<?= $recordId ?>">
                            Edit attendance &middot; <?= e(format_date($record['attendance_date'])) ?>
                        </h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="edit-pa-professor<?= $recordId ?>">Professor</label>
                                <select class="form-select" id="edit-pa-professor<?= $recordId ?>"
                                        name="professor_id" required>
                                    <?php
                                    $professorFound = false;
                                    foreach ($professors as $professor):
                                        if ($professor['professor_id'] === $record['professor_id']) {
                                            $professorFound = true;
                                        }
                                        ?>
                                        <option value="<?= e($professor['professor_id']) ?>"
                                            <?= selected($record['professor_id'], $professor['professor_id']) ?>>
                                            <?= e($professor['fullname']) ?> (<?= e($professor['professor_id']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                    <?php if (!$professorFound): ?>
                                        <option value="<?= e($record['professor_id']) ?>" selected>
                                            <?= e($record['professor_id']) ?> (current)
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-pa-date<?= $recordId ?>">Date</label>
                                <input type="date" class="form-control" id="edit-pa-date<?= $recordId ?>"
                                       name="attendance_date" value="<?= e($record['attendance_date']) ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-pa-time-in<?= $recordId ?>">Time in</label>
                                <input type="time" class="form-control" id="edit-pa-time-in<?= $recordId ?>"
                                       name="time_in"
                                       value="<?= e($record['time_in'] !== null ? substr((string) $record['time_in'], 0, 5) : '') ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-pa-time-out<?= $recordId ?>">Time out</label>
                                <input type="time" class="form-control" id="edit-pa-time-out<?= $recordId ?>"
                                       name="time_out"
                                       value="<?= e($record['time_out'] !== null ? substr((string) $record['time_out'], 0, 5) : '') ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-pa-status<?= $recordId ?>">Status</label>
                                <select class="form-select" id="edit-pa-status<?= $recordId ?>" name="status" required>
                                    <?php foreach ($statusOptions as $status): ?>
                                        <option value="<?= e($status) ?>" <?= selected($record['status'], $status) ?>>
                                            <?= e($status) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-pa-remarks<?= $recordId ?>">Remarks</label>
                                <input type="text" class="form-control" id="edit-pa-remarks<?= $recordId ?>"
                                       name="remarks" value="<?= e($record['remarks'] ?? '') ?>" maxlength="255">
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

<?php require __DIR__ . '/inc/footer.php'; ?>
