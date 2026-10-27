<?php
/**
 * Printable reports (Admin and Professor).
 *
 * Administrators can run every report. Professors only see attendance and
 * grades recorded under their own Professor ID. All searching uses prepared
 * statements and every output value is escaped.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

require_role(['Admin', 'Professor']);

$role = current_role();
$userId = current_user_id();

$reportLabels = [
    'students' => 'Student Report',
    'professors' => 'Professor Report',
    'courses' => 'Course Report',
    'attendance' => 'Attendance Report',
    'grades' => 'Grades Report',
];

$allowedReports = $role === 'Admin'
    ? array_keys($reportLabels)
    : ['attendance', 'grades'];

$report = query_string('report', $allowedReports[0]);
if (!in_array($report, $allowedReports, true)) {
    $report = $allowedReports[0];
}

$search = mb_substr(query_string('search'), 0, 100);

$professorId = null;
if ($role === 'Professor') {
    $stmt = $conn->prepare(
        "SELECT professor_id FROM users WHERE id = ? AND role = 'Professor' LIMIT 1"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $professorId = (string) ($stmt->get_result()->fetch_assoc()['professor_id'] ?? '');
    $stmt->close();

    if ($professorId === '') {
        abort(403, 'Your account is not linked to a professor profile. Ask an administrator to link it.');
    }
}

/** Run a prepared query and return every row. */
function fetch_report_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if ($types !== '' && $params !== []) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

$columns = [];
$rows = [];
$statusColumns = ['Status'];

if ($report === 'students') {
    $where = ["s.status = 'Active'"];
    $types = '';
    $params = [];

    if ($search !== '') {
        $where[] = '(s.student_id LIKE ? OR s.fullname LIKE ? OR s.course LIKE ? OR s.section LIKE ? OR s.email LIKE ?)';
        $types = 'sssss';
        $term = '%' . $search . '%';
        $params = [$term, $term, $term, $term, $term];
    }

    $sql = 'SELECT s.* FROM students s WHERE ' . implode(' AND ', $where) . ' ORDER BY s.student_id';
    $rows = fetch_report_rows($conn, $sql, $types, $params);
    $columns = ['Student ID', 'Full name', 'Gender', 'Course', 'Year level', 'Section', 'Email', 'Status'];
} elseif ($report === 'professors') {
    $where = ["p.status = 'Active'"];
    $types = '';
    $params = [];

    if ($search !== '') {
        $where[] = '(p.professor_id LIKE ? OR p.fullname LIKE ? OR p.department LIKE ? OR p.specialization LIKE ? OR p.email LIKE ?)';
        $types = 'sssss';
        $term = '%' . $search . '%';
        $params = [$term, $term, $term, $term, $term];
    }

    $sql = 'SELECT p.* FROM professors p WHERE ' . implode(' AND ', $where) . ' ORDER BY p.professor_id';
    $rows = fetch_report_rows($conn, $sql, $types, $params);
    $columns = ['Professor ID', 'Full name', 'Gender', 'Department', 'Specialization', 'Email', 'Contact', 'Status'];
} elseif ($report === 'courses') {
    $where = ["c.status = 'Active'"];
    $types = '';
    $params = [];

    if ($search !== '') {
        $where[] = '(c.course_code LIKE ? OR c.course_name LIKE ? OR c.department LIKE ?)';
        $types = 'sss';
        $term = '%' . $search . '%';
        $params = [$term, $term, $term];
    }

    $sql = 'SELECT c.* FROM courses c WHERE ' . implode(' AND ', $where) . ' ORDER BY c.course_code';
    $rows = fetch_report_rows($conn, $sql, $types, $params);
    $columns = ['Course code', 'Course name', 'Department', 'Units', 'Description', 'Status'];
} elseif ($report === 'attendance') {
    $where = [];
    $types = '';
    $params = [];

    if ($role === 'Professor') {
        $where[] = 'a.professor_id = ?';
        $types .= 's';
        $params[] = $professorId;
    }

    if ($search !== '') {
        $where[] = '(a.student_id LIKE ? OR st.fullname LIKE ? OR a.course_code LIKE ? OR c.course_name LIKE ? OR a.professor_id LIKE ? OR p.fullname LIKE ? OR a.status LIKE ?)';
        $types .= 'sssssss';
        $term = '%' . $search . '%';
        array_push($params, $term, $term, $term, $term, $term, $term, $term);
    }

    $sql = 'SELECT a.attendance_date, a.student_id, st.fullname AS student_name,
                   a.course_code, c.course_name, a.professor_id, p.fullname AS professor_name,
                   a.status
            FROM attendance a
            LEFT JOIN students st ON st.student_id = a.student_id
            LEFT JOIN courses c ON c.course_code = a.course_code
            LEFT JOIN professors p ON p.professor_id = a.professor_id';

    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY a.attendance_date DESC, a.id DESC';

    $rows = fetch_report_rows($conn, $sql, $types, $params);
    $columns = ['Date', 'Student', 'Course', 'Professor', 'Status'];
} else {
    // Grades report.
    $where = ["g.status = 'Active'"];
    $types = '';
    $params = [];

    if ($role === 'Professor') {
        $where[] = 'g.professor_id = ?';
        $types .= 's';
        $params[] = $professorId;
    }

    if ($search !== '') {
        $where[] = '(g.student_id LIKE ? OR st.fullname LIKE ? OR g.course_code LIKE ? OR c.course_name LIKE ? OR g.professor_id LIKE ? OR p.fullname LIKE ? OR g.semester LIKE ? OR g.school_year LIKE ?)';
        $types .= 'ssssssss';
        $term = '%' . $search . '%';
        array_push($params, $term, $term, $term, $term, $term, $term, $term, $term);
    }

    $sql = 'SELECT g.student_id, st.fullname AS student_name,
                   g.course_code, c.course_name,
                   g.professor_id, p.fullname AS professor_name,
                   g.grade, g.semester, g.school_year
            FROM grades g
            LEFT JOIN students st ON st.student_id = g.student_id
            LEFT JOIN courses c ON c.course_code = g.course_code
            LEFT JOIN professors p ON p.professor_id = g.professor_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY g.school_year DESC, g.semester, g.student_id';

    $rows = fetch_report_rows($conn, $sql, $types, $params);
    $columns = ['Student', 'Course', 'Professor', 'Grade', 'Semester', 'School year'];
}

/** Turn a row into display cells that match the current report. */
function report_cells(string $report, array $row): array
{
    return match ($report) {
        'students' => [
            $row['student_id'],
            $row['fullname'],
            $row['gender'],
            $row['course'],
            $row['year_level'],
            $row['section'],
            $row['email'],
            $row['status'],
        ],
        'professors' => [
            $row['professor_id'],
            $row['fullname'],
            $row['gender'],
            $row['department'],
            $row['specialization'],
            $row['email'],
            $row['contact_no'],
            $row['status'],
        ],
        'courses' => [
            $row['course_code'],
            $row['course_name'],
            $row['department'],
            $row['units'],
            $row['description'],
            $row['status'],
        ],
        'attendance' => [
            format_date($row['attendance_date']),
            ($row['student_name'] ?? $row['student_id']) . ' (' . $row['student_id'] . ')',
            $row['course_code'] . (($row['course_name'] ?? '') !== '' ? ' — ' . $row['course_name'] : ''),
            ($row['professor_name'] ?? '') . ' (' . $row['professor_id'] . ')',
            $row['status'],
        ],
        default => [
            ($row['student_name'] ?? $row['student_id']) . ' (' . $row['student_id'] . ')',
            $row['course_code'] . (($row['course_name'] ?? '') !== '' ? ' — ' . $row['course_name'] : ''),
            ($row['professor_name'] ?? '') . ' (' . $row['professor_id'] . ')',
            number_format((float) $row['grade'], 2),
            $row['semester'],
            $row['school_year'],
        ],
    };
}

$pageTitle = 'Reports';
$pageSubtitle = $role === 'Professor'
    ? 'Attendance and grade reports for your classes'
    : 'Printable academic summaries';
$active = 'reports';
$pageActions = '<button type="button" class="btn btn-dark btn-sm no-print" onclick="window.print()">'
    . '<i class="fa-solid fa-print me-1" aria-hidden="true"></i>Print report</button>';
require __DIR__ . '/inc/header.php';
?>

<style>
@media print {
    .appbar,
    .toolbar,
    .report-tabs,
    .page-head-actions,
    .skip-link,
    .no-print {
        display: none !important;
    }
    body {
        background: #fff;
    }
    .page-shell {
        padding: 0;
        max-width: none;
    }
    .page-head {
        margin-bottom: 12px;
    }
    .card {
        border: 1px solid #ccc;
        box-shadow: none;
    }
}
</style>

<div class="report-tabs">
    <?php foreach ($allowedReports as $key): ?>
        <a class="btn btn-sm <?= $report === $key ? 'btn-primary' : 'btn-outline-secondary' ?>"
           href="reports.php?report=<?= e($key) ?>">
            <?= e($reportLabels[$key]) ?>
        </a>
    <?php endforeach; ?>
</div>

<form class="toolbar" method="get" action="reports.php">
    <input type="hidden" name="report" value="<?= e($report) ?>">

    <div class="search-box">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <label class="visually-hidden" for="report-search">Search report</label>
        <input type="search" class="form-control" id="report-search" name="search"
               value="<?= e($search) ?>" placeholder="Search this report">
    </div>

    <button class="btn btn-primary" type="submit">
        <i class="fa-solid fa-filter me-1" aria-hidden="true"></i>Search
    </button>

    <?php if ($search !== ''): ?>
        <a class="btn btn-outline-secondary" href="reports.php?report=<?= e($report) ?>">Reset</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
            <div>
                <h2 class="h4 mb-1"><?= e($reportLabels[$report]) ?></h2>
                <p class="text-muted mb-0">
                    <?= count($rows) ?> record<?= count($rows) === 1 ? '' : 's' ?>
                    &middot; Generated <?= e(date('M j, Y g:i A')) ?>
                    <?php if ($role === 'Professor'): ?>
                        &middot; Professor <?= e($professorId) ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle report-table">
                <thead>
                    <tr>
                        <?php foreach ($columns as $column): ?>
                            <th scope="col"><?= e($column) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []): ?>
                        <tr>
                            <td colspan="<?= count($columns) ?>">
                                <div class="empty-state">
                                    <i class="fa-solid fa-chart-column" aria-hidden="true"></i>
                                    <h5>No records</h5>
                                    <p>Try a different search.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($rows as $row): ?>
                        <?php $cells = report_cells($report, $row); ?>
                        <tr>
                            <?php foreach ($cells as $index => $cell): ?>
                                <td>
                                    <?php if (in_array($columns[$index] ?? '', $statusColumns, true)): ?>
                                        <span class="badge text-bg-<?= e(status_badge_class((string) $cell)) ?>">
                                            <?= e($cell) ?>
                                        </span>
                                    <?php else: ?>
                                        <?= ($cell ?? '') !== '' ? e($cell) : '<span class="text-muted">—</span>' ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/inc/footer.php'; ?>
