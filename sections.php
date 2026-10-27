<?php
/**
 * Section register (Admin only).
 *
 * Section codes are immutable because students and schedules reference them as
 * text. This register adds the program, year level and adviser that those text
 * columns cannot describe. The student and schedule counts are derived, so
 * the register never disagrees with the data it describes.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

require_role('Admin');

$yearLevels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];

/** Fetch one section row by primary key. */
function find_section(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare('SELECT * FROM sections WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/** Validate the shared section form payload. @return array<int,string> */
function section_errors(mysqli $conn, array $input, array $yearLevels): array
{
    $errors = [];

    if (!preg_match('/^[A-Za-z0-9-]{1,20}$/', $input['section_code'])) {
        $errors[] = 'Section code may only contain letters, numbers, and hyphens (maximum 20 characters).';
    }
    if ($input['course_code'] === '' || !course_exists($conn, $input['course_code'])) {
        $errors[] = 'Choose a program for the section.';
    }
    if (!in_list($input['year_level'], $yearLevels)) {
        $errors[] = 'Choose a valid year level.';
    }
    if ($input['adviser_id'] !== '' && !professor_exists($conn, $input['adviser_id'])) {
        $errors[] = 'Choose an adviser from the professor list.';
    }

    return $errors;
}

if (is_post()) {
    csrf_verify();

    $action = post_string('action');

    if ($action === 'add_section') {
        $input = [
            'section_code' => strtoupper(post_string('section_code')),
            'course_code' => strtoupper(post_string('course_code')),
            'year_level' => post_string('year_level'),
            'adviser_id' => strtoupper(post_string('adviser_id')),
        ];

        $errors = section_errors($conn, $input, $yearLevels);

        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect('sections.php');
        }

        try {
            $stmt = $conn->prepare(
                "INSERT INTO sections (section_code, course_code, year_level, adviser_id, status)
                 VALUES (?, ?, ?, ?, 'Active')"
            );
            $stmt->bind_param(
                'ssss',
                $input['section_code'],
                $input['course_code'],
                $input['year_level'],
                $input['adviser_id']
            );
            $stmt->execute();
            $stmt->close();

            flash('success', 'Section added successfully.');
        } catch (mysqli_sql_exception $e) {
            error_log('Add section failed: ' . $e->getMessage());
            flash('danger', is_unique_violation($e)
                ? 'That section code already exists.'
                : 'Unable to add the section. Please try again.');
        } catch (Throwable $e) {
            error_log('Add section failed: ' . $e->getMessage());
            flash('danger', 'Unable to add the section. Please try again.');
        }

        redirect('sections.php');
    }

    if ($action === 'update_section') {
        $section = find_section($conn, post_int('id'));

        if ($section === null) {
            flash('danger', 'Section not found.');
            redirect('sections.php');
        }

        $input = [
            'section_code' => $section['section_code'],
            'course_code' => strtoupper(post_string('course_code')),
            'year_level' => post_string('year_level'),
            'adviser_id' => strtoupper(post_string('adviser_id')),
        ];

        $errors = section_errors($conn, $input, $yearLevels);

        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect('sections.php');
        }

        try {
            $stmt = $conn->prepare(
                'UPDATE sections
                 SET course_code = ?, year_level = ?, adviser_id = ?
                 WHERE id = ?'
            );
            $stmt->bind_param(
                'sssi',
                $input['course_code'],
                $input['year_level'],
                $input['adviser_id'],
                $section['id']
            );
            $stmt->execute();
            $stmt->close();

            flash('success', 'Section updated successfully.');
        } catch (Throwable $e) {
            error_log('Update section failed: ' . $e->getMessage());
            flash('danger', 'Unable to update the section. Please try again.');
        }

        redirect('sections.php');
    }

    if ($action === 'set_status') {
        $section = find_section($conn, post_int('id'));
        $status = post_string('status');

        if ($section === null) {
            flash('danger', 'Section not found.');
            redirect('sections.php');
        }

        if (!in_list($status, ['Active', 'Inactive'])) {
            flash('danger', 'Invalid status.');
            redirect('sections.php');
        }

        try {
            $stmt = $conn->prepare('UPDATE sections SET status = ? WHERE id = ?');
            $stmt->bind_param('si', $status, $section['id']);
            $stmt->execute();
            $stmt->close();

            flash('success', $status === 'Active'
                ? 'Section activated.'
                : 'Section deactivated. Students and schedules keep the section code.');
        } catch (Throwable $e) {
            error_log('Section status change failed: ' . $e->getMessage());
            flash('danger', 'Unable to change the section status. Please try again.');
        }

        redirect('sections.php');
    }

    flash('danger', 'Unknown action.');
    redirect('sections.php');
}

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------

$search = mb_substr(query_string('search'), 0, 100);
$statusFilter = query_string('status', 'All');
$programFilter = strtoupper(mb_substr(query_string('program'), 0, 20));

if (!in_list($statusFilter, ['All', 'Active', 'Inactive'])) {
    $statusFilter = 'All';
}

$where = [];
$types = '';
$params = [];

if ($statusFilter !== 'All') {
    $where[] = 'sec.status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}

if ($programFilter !== '') {
    $where[] = 'sec.course_code = ?';
    $types .= 's';
    $params[] = $programFilter;
}

if ($search !== '') {
    $where[] = '(sec.section_code LIKE ? OR sec.course_code LIKE ? OR c.course_name LIKE ? OR sec.adviser_id LIKE ? OR p.fullname LIKE ?)';
    $types .= 'sssss';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term, $term);
}

$sql = "SELECT sec.*,
               c.course_name,
               p.fullname AS adviser_name,
               (SELECT COUNT(*) FROM students st
                 WHERE st.section = sec.section_code AND st.status = 'Active') AS student_count,
               (SELECT COUNT(*) FROM schedules sch
                 WHERE sch.section = sec.section_code AND sch.status = 'Active') AS schedule_count
        FROM sections sec
        LEFT JOIN courses c ON c.course_code = sec.course_code
        LEFT JOIN professors p ON p.professor_id = sec.adviser_id";

if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY sec.section_code ASC';

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$sections = [];
while ($row = $result->fetch_assoc()) {
    $sections[] = $row;
}
$stmt->close();

$programs = [];
$programResult = $conn->query(
    'SELECT course_code, course_name, status FROM courses ORDER BY course_code'
);
while ($row = $programResult->fetch_assoc()) {
    $programs[] = $row;
}

$advisers = [];
$adviserResult = $conn->query(
    'SELECT professor_id, fullname, status FROM professors ORDER BY fullname'
);
while ($row = $adviserResult->fetch_assoc()) {
    $advisers[] = $row;
}

$pageTitle = 'Sections';
$pageSubtitle = 'Class sections, year levels, and advisers';
$active = 'sections';
$pageActions = '<button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addSection">'
    . '<i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add section</button>';
require __DIR__ . '/inc/header.php';
?>

<form class="toolbar" method="get" action="sections.php">
    <div class="search-box">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <label class="visually-hidden" for="section-search">Search sections</label>
        <input type="search" class="form-control" id="section-search" name="search"
               value="<?= e($search) ?>" placeholder="Search section, program, or adviser">
    </div>

    <label class="visually-hidden" for="section-program">Filter by program</label>
    <select class="form-select" id="section-program" name="program">
        <option value="">All programs</option>
        <?php foreach ($programs as $program): ?>
            <option value="<?= e($program['course_code']) ?>"
                <?= selected($programFilter, $program['course_code']) ?>>
                <?= e($program['course_code']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label class="visually-hidden" for="section-status">Filter by status</label>
    <select class="form-select" id="section-status" name="status">
        <option value="All" <?= selected($statusFilter, 'All') ?>>All statuses</option>
        <option value="Active" <?= selected($statusFilter, 'Active') ?>>Active</option>
        <option value="Inactive" <?= selected($statusFilter, 'Inactive') ?>>Inactive</option>
    </select>

    <button class="btn btn-primary" type="submit">
        <i class="fa-solid fa-filter me-1" aria-hidden="true"></i>Filter
    </button>

    <?php if ($search !== '' || $statusFilter !== 'All' || $programFilter !== ''): ?>
        <a class="btn btn-outline-secondary" href="sections.php">Reset</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th scope="col">Section</th>
                    <th scope="col">Program</th>
                    <th scope="col">Year level</th>
                    <th scope="col">Adviser</th>
                    <th scope="col" class="text-center">Students</th>
                    <th scope="col" class="text-center">Schedules</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($sections === []): ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                                <h5>No sections found</h5>
                                <p>Try a different search or filter, or add the first section.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($sections as $section): ?>
                    <tr>
                        <td class="mono"><?= e($section['section_code']) ?></td>
                        <td>
                            <?php if (($section['course_code'] ?? '') !== ''): ?>
                                <span class="mono"><?= e($section['course_code']) ?></span>
                                <?php if (($section['course_name'] ?? '') !== ''): ?>
                                    <div class="text-muted small"><?= e($section['course_name']) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= ($section['year_level'] ?? '') !== '' ? e($section['year_level']) : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <?php if (($section['adviser_name'] ?? '') !== ''): ?>
                                <?= e($section['adviser_name']) ?>
                                <div class="text-muted small mono"><?= e($section['adviser_id']) ?></div>
                            <?php else: ?>
                                <span class="text-muted">No adviser</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <a class="badge text-bg-secondary text-decoration-none"
                               href="students.php?search=<?= e(rawurlencode($section['section_code'])) ?>"
                               title="View students in this section">
                                <?= (int) $section['student_count'] ?>
                            </a>
                        </td>
                        <td class="text-center">
                            <a class="badge text-bg-secondary text-decoration-none"
                               href="schedules.php?search=<?= e(rawurlencode($section['section_code'])) ?>"
                               title="View schedules for this section">
                                <?= (int) $section['schedule_count'] ?>
                            </a>
                        </td>
                        <td>
                            <span class="badge text-bg-<?= e(status_badge_class($section['status'])) ?>">
                                <?= e($section['status']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="actions justify-content-end">
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editSection<?= (int) $section['id'] ?>">
                                    Edit
                                </button>

                                <?php if ($section['status'] === 'Active'): ?>
                                    <form method="post" data-confirm="Deactivate <?= e($section['section_code']) ?>? Students and schedules keep the code; only the register entry is marked inactive.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="id" value="<?= (int) $section['id'] ?>">
                                        <input type="hidden" name="status" value="Inactive">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="id" value="<?= (int) $section['id'] ?>">
                                        <input type="hidden" name="status" value="Active">
                                        <button type="submit" class="btn btn-sm btn-outline-success">Activate</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add section -->
<div class="modal fade" id="addSection" tabindex="-1" aria-labelledby="addSectionLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_section">

                <div class="modal-header">
                    <h2 class="modal-title h5" id="addSectionLabel">Add section</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="add-section-code">Section code</label>
                            <input type="text" class="form-control" id="add-section-code" name="section_code"
                                   maxlength="20" placeholder="e.g. IT1A" required>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label" for="add-section-program">Program</label>
                            <select class="form-select" id="add-section-program" name="course_code" required>
                                <option value="">Select program</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?= e($program['course_code']) ?>">
                                        <?= e($program['course_code']) ?> — <?= e($program['course_name']) ?>
                                        <?= $program['status'] === 'Active' ? '' : ' (inactive)' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-section-year">Year level</label>
                            <select class="form-select" id="add-section-year" name="year_level" required>
                                <option value="">Select year level</option>
                                <?php foreach ($yearLevels as $yearLevel): ?>
                                    <option value="<?= e($yearLevel) ?>"><?= e($yearLevel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-section-adviser">Adviser</label>
                            <select class="form-select" id="add-section-adviser" name="adviser_id">
                                <option value="">No adviser</option>
                                <?php foreach ($advisers as $adviser): ?>
                                    <option value="<?= e($adviser['professor_id']) ?>">
                                        <?= e($adviser['fullname']) ?> (<?= e($adviser['professor_id']) ?>)
                                        <?= $adviser['status'] === 'Active' ? '' : ' — inactive' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add section</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php foreach ($sections as $section): ?>
    <?php $sectionId = (int) $section['id']; ?>

    <div class="modal fade" id="editSection<?= $sectionId ?>" tabindex="-1"
         aria-labelledby="editSectionLabel<?= $sectionId ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_section">
                    <input type="hidden" name="id" value="<?= $sectionId ?>">

                    <div class="modal-header">
                        <h2 class="modal-title h5" id="editSectionLabel<?= $sectionId ?>">
                            Edit <?= e($section['section_code']) ?>
                        </h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <span class="form-label d-block">Section code</span>
                                <p class="mono mb-0"><?= e($section['section_code']) ?></p>
                                <p class="form-hint">Section codes are permanent because students and schedules reference them.</p>
                            </div>

                            <div class="col-md-8">
                                <label class="form-label" for="edit-section-program<?= $sectionId ?>">Program</label>
                                <select class="form-select" id="edit-section-program<?= $sectionId ?>"
                                        name="course_code" required>
                                    <option value="">Select program</option>
                                    <?php foreach ($programs as $program): ?>
                                        <option value="<?= e($program['course_code']) ?>"
                                            <?= selected($section['course_code'], $program['course_code']) ?>>
                                            <?= e($program['course_code']) ?> — <?= e($program['course_name']) ?>
                                            <?= $program['status'] === 'Active' ? '' : ' (inactive)' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-section-year<?= $sectionId ?>">Year level</label>
                                <select class="form-select" id="edit-section-year<?= $sectionId ?>"
                                        name="year_level" required>
                                    <option value="">Select year level</option>
                                    <?php foreach ($yearLevels as $yearLevel): ?>
                                        <option value="<?= e($yearLevel) ?>"
                                            <?= selected($section['year_level'], $yearLevel) ?>>
                                            <?= e($yearLevel) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-section-adviser<?= $sectionId ?>">Adviser</label>
                                <select class="form-select" id="edit-section-adviser<?= $sectionId ?>"
                                        name="adviser_id">
                                    <option value="">No adviser</option>
                                    <?php foreach ($advisers as $adviser): ?>
                                        <option value="<?= e($adviser['professor_id']) ?>"
                                            <?= selected($section['adviser_id'], $adviser['professor_id']) ?>>
                                            <?= e($adviser['fullname']) ?> (<?= e($adviser['professor_id']) ?>)
                                            <?= $adviser['status'] === 'Active' ? '' : ' — inactive' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
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
