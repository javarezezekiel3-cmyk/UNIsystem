<?php
/**
 * Subject catalogue (Admin only).
 *
 * Subject codes are immutable once created so the catalogue stays stable while
 * schedules and grade sheets grow around it. Deactivating a subject keeps the
 * record for reporting but removes it from the active pick lists.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

require_role('Admin');

/** Fetch one subject row by primary key. */
function find_subject(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare('SELECT * FROM subjects WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/** Validate the shared subject form payload. @return array<int,string> */
function subject_errors(mysqli $conn, array $input): array
{
    $errors = [];

    if (!preg_match('/^[A-Za-z0-9-]{1,20}$/', $input['subject_code'])) {
        $errors[] = 'Subject code may only contain letters, numbers, and hyphens (maximum 20 characters).';
    }
    if (mb_strlen($input['subject_name']) < 2) {
        $errors[] = 'Enter the subject title.';
    }
    if ($input['units'] < 0 || $input['units'] > 20) {
        $errors[] = 'Units must be between 0 and 20.';
    }
    if ($input['course_code'] !== '' && !course_exists($conn, $input['course_code'])) {
        $errors[] = 'Choose a program from the course list.';
    }
    if (mb_strlen($input['description']) > 1000) {
        $errors[] = 'The description is too long (maximum 1000 characters).';
    }

    return $errors;
}

if (is_post()) {
    csrf_verify();

    $action = post_string('action');

    if ($action === 'add_subject') {
        $input = [
            'subject_code' => strtoupper(post_string('subject_code')),
            'subject_name' => mb_substr(post_string('subject_name'), 0, 100),
            'units' => post_int('units'),
            'course_code' => strtoupper(post_string('course_code')),
            'description' => mb_substr(post_string('description'), 0, 1000),
        ];

        $errors = subject_errors($conn, $input);

        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect('subjects.php');
        }

        try {
            $stmt = $conn->prepare(
                "INSERT INTO subjects (subject_code, subject_name, units, course_code, description, status)
                 VALUES (?, ?, ?, ?, ?, 'Active')"
            );
            $stmt->bind_param(
                'ssiss',
                $input['subject_code'],
                $input['subject_name'],
                $input['units'],
                $input['course_code'],
                $input['description']
            );
            $stmt->execute();
            $stmt->close();

            flash('success', 'Subject added successfully.');
        } catch (mysqli_sql_exception $e) {
            error_log('Add subject failed: ' . $e->getMessage());
            flash('danger', is_unique_violation($e)
                ? 'That subject code already exists.'
                : 'Unable to add the subject. Please try again.');
        } catch (Throwable $e) {
            error_log('Add subject failed: ' . $e->getMessage());
            flash('danger', 'Unable to add the subject. Please try again.');
        }

        redirect('subjects.php');
    }

    if ($action === 'update_subject') {
        $subject = find_subject($conn, post_int('id'));

        if ($subject === null) {
            flash('danger', 'Subject not found.');
            redirect('subjects.php');
        }

        $input = [
            'subject_code' => $subject['subject_code'],
            'subject_name' => mb_substr(post_string('subject_name'), 0, 100),
            'units' => post_int('units'),
            'course_code' => strtoupper(post_string('course_code')),
            'description' => mb_substr(post_string('description'), 0, 1000),
        ];

        $errors = subject_errors($conn, $input);

        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect('subjects.php');
        }

        try {
            $stmt = $conn->prepare(
                'UPDATE subjects
                 SET subject_name = ?, units = ?, course_code = ?, description = ?
                 WHERE id = ?'
            );
            $stmt->bind_param(
                'sissi',
                $input['subject_name'],
                $input['units'],
                $input['course_code'],
                $input['description'],
                $subject['id']
            );
            $stmt->execute();
            $stmt->close();

            flash('success', 'Subject updated successfully.');
        } catch (Throwable $e) {
            error_log('Update subject failed: ' . $e->getMessage());
            flash('danger', 'Unable to update the subject. Please try again.');
        }

        redirect('subjects.php');
    }

    if ($action === 'set_status') {
        $subject = find_subject($conn, post_int('id'));
        $status = post_string('status');

        if ($subject === null) {
            flash('danger', 'Subject not found.');
            redirect('subjects.php');
        }

        if (!in_list($status, ['Active', 'Inactive'])) {
            flash('danger', 'Invalid status.');
            redirect('subjects.php');
        }

        try {
            $stmt = $conn->prepare('UPDATE subjects SET status = ? WHERE id = ?');
            $stmt->bind_param('si', $status, $subject['id']);
            $stmt->execute();
            $stmt->close();

            flash('success', $status === 'Active' ? 'Subject activated.' : 'Subject deactivated.');
        } catch (Throwable $e) {
            error_log('Subject status change failed: ' . $e->getMessage());
            flash('danger', 'Unable to change the subject status. Please try again.');
        }

        redirect('subjects.php');
    }

    flash('danger', 'Unknown action.');
    redirect('subjects.php');
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
    $where[] = 'sub.status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}

if ($programFilter !== '') {
    $where[] = 'sub.course_code = ?';
    $types .= 's';
    $params[] = $programFilter;
}

if ($search !== '') {
    $where[] = '(sub.subject_code LIKE ? OR sub.subject_name LIKE ? OR sub.description LIKE ?)';
    $types .= 'sss';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term);
}

$sql = 'SELECT sub.*, c.course_name
        FROM subjects sub
        LEFT JOIN courses c ON c.course_code = sub.course_code';

if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY sub.subject_code ASC';

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$subjects = [];
while ($row = $result->fetch_assoc()) {
    $subjects[] = $row;
}
$stmt->close();

$programs = [];
$programResult = $conn->query(
    'SELECT course_code, course_name, status FROM courses ORDER BY course_code'
);
while ($row = $programResult->fetch_assoc()) {
    $programs[] = $row;
}

$pageTitle = 'Subjects';
$pageSubtitle = 'Subject codes, titles, units, and programs';
$active = 'subjects';
$pageActions = '<button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addSubject">'
    . '<i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add subject</button>';
require __DIR__ . '/inc/header.php';
?>

<form class="toolbar" method="get" action="subjects.php">
    <div class="search-box">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <label class="visually-hidden" for="subject-search">Search subjects</label>
        <input type="search" class="form-control" id="subject-search" name="search"
               value="<?= e($search) ?>" placeholder="Search code, title, or description">
    </div>

    <label class="visually-hidden" for="subject-program">Filter by program</label>
    <select class="form-select" id="subject-program" name="program">
        <option value="">All programs</option>
        <?php foreach ($programs as $program): ?>
            <option value="<?= e($program['course_code']) ?>"
                <?= selected($programFilter, $program['course_code']) ?>>
                <?= e($program['course_code']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label class="visually-hidden" for="subject-status">Filter by status</label>
    <select class="form-select" id="subject-status" name="status">
        <option value="All" <?= selected($statusFilter, 'All') ?>>All statuses</option>
        <option value="Active" <?= selected($statusFilter, 'Active') ?>>Active</option>
        <option value="Inactive" <?= selected($statusFilter, 'Inactive') ?>>Inactive</option>
    </select>

    <button class="btn btn-primary" type="submit">
        <i class="fa-solid fa-filter me-1" aria-hidden="true"></i>Filter
    </button>

    <?php if ($search !== '' || $statusFilter !== 'All' || $programFilter !== ''): ?>
        <a class="btn btn-outline-secondary" href="subjects.php">Reset</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th scope="col">Code</th>
                    <th scope="col">Subject</th>
                    <th scope="col">Program</th>
                    <th scope="col">Units</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($subjects === []): ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <i class="fa-solid fa-book" aria-hidden="true"></i>
                                <h5>No subjects found</h5>
                                <p>Try a different search or filter, or add the first subject.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($subjects as $subject): ?>
                    <tr>
                        <td class="mono"><?= e($subject['subject_code']) ?></td>
                        <td>
                            <strong><?= e($subject['subject_name']) ?></strong>
                            <?php if (($subject['description'] ?? '') !== ''): ?>
                                <div class="text-muted small text-truncate" style="max-width: 320px;">
                                    <?= e($subject['description']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (($subject['course_code'] ?? '') !== ''): ?>
                                <span class="mono"><?= e($subject['course_code']) ?></span>
                                <?php if (($subject['course_name'] ?? '') !== ''): ?>
                                    <div class="text-muted small"><?= e($subject['course_name']) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $subject['units'] ?></td>
                        <td>
                            <span class="badge text-bg-<?= e(status_badge_class($subject['status'])) ?>">
                                <?= e($subject['status']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="actions justify-content-end">
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editSubject<?= (int) $subject['id'] ?>">
                                    Edit
                                </button>

                                <?php if ($subject['status'] === 'Active'): ?>
                                    <form method="post" data-confirm="Deactivate <?= e($subject['subject_code']) ?>? It stays in the catalogue but is marked inactive.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="id" value="<?= (int) $subject['id'] ?>">
                                        <input type="hidden" name="status" value="Inactive">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="id" value="<?= (int) $subject['id'] ?>">
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

<!-- Add subject -->
<div class="modal fade" id="addSubject" tabindex="-1" aria-labelledby="addSubjectLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_subject">

                <div class="modal-header">
                    <h2 class="modal-title h5" id="addSubjectLabel">Add subject</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="add-subject-code">Subject code</label>
                            <input type="text" class="form-control" id="add-subject-code" name="subject_code"
                                   maxlength="20" placeholder="e.g. IT101" required>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label" for="add-subject-name">Subject title</label>
                            <input type="text" class="form-control" id="add-subject-name" name="subject_name"
                                   maxlength="100" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-subject-program">Program</label>
                            <select class="form-select" id="add-subject-program" name="course_code">
                                <option value="">Not set</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?= e($program['course_code']) ?>">
                                        <?= e($program['course_code']) ?> — <?= e($program['course_name']) ?>
                                        <?= $program['status'] === 'Active' ? '' : ' (inactive)' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-subject-units">Units</label>
                            <input type="number" class="form-control" id="add-subject-units" name="units"
                                   min="0" max="20" step="1" value="3" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="add-subject-description">Description</label>
                            <textarea class="form-control" id="add-subject-description" name="description"
                                      maxlength="1000" rows="3"></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add subject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php foreach ($subjects as $subject): ?>
    <?php $subjectId = (int) $subject['id']; ?>

    <div class="modal fade" id="editSubject<?= $subjectId ?>" tabindex="-1"
         aria-labelledby="editSubjectLabel<?= $subjectId ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_subject">
                    <input type="hidden" name="id" value="<?= $subjectId ?>">

                    <div class="modal-header">
                        <h2 class="modal-title h5" id="editSubjectLabel<?= $subjectId ?>">
                            Edit <?= e($subject['subject_code']) ?>
                        </h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <span class="form-label d-block">Subject code</span>
                                <p class="mono mb-0"><?= e($subject['subject_code']) ?></p>
                                <p class="form-hint">Subject codes are permanent once created.</p>
                            </div>

                            <div class="col-md-8">
                                <label class="form-label" for="edit-subject-name<?= $subjectId ?>">Subject title</label>
                                <input type="text" class="form-control" id="edit-subject-name<?= $subjectId ?>"
                                       name="subject_name" value="<?= e($subject['subject_name']) ?>"
                                       maxlength="100" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-subject-program<?= $subjectId ?>">Program</label>
                                <select class="form-select" id="edit-subject-program<?= $subjectId ?>" name="course_code">
                                    <option value="">Not set</option>
                                    <?php foreach ($programs as $program): ?>
                                        <option value="<?= e($program['course_code']) ?>"
                                            <?= selected($subject['course_code'], $program['course_code']) ?>>
                                            <?= e($program['course_code']) ?> — <?= e($program['course_name']) ?>
                                            <?= $program['status'] === 'Active' ? '' : ' (inactive)' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-subject-units<?= $subjectId ?>">Units</label>
                                <input type="number" class="form-control" id="edit-subject-units<?= $subjectId ?>"
                                       name="units" min="0" max="20" step="1"
                                       value="<?= (int) $subject['units'] ?>" required>
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="edit-subject-description<?= $subjectId ?>">Description</label>
                                <textarea class="form-control" id="edit-subject-description<?= $subjectId ?>"
                                          name="description" maxlength="1000" rows="3"><?= e($subject['description'] ?? '') ?></textarea>
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
