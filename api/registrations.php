<?php
/**
 * Member self-registration API.
 *
 *   submit     PUBLIC  — a visitor requests a profile (lands as pending)
 *   list       admin   — review the queue (gender-scoped for sectioned staff)
 *   next_code  admin   — suggested next free member code
 *   approve    admin   — create the member + record first payment + activate
 *   reject     admin   — decline a pending request
 *
 * Auth is per-action (NOT a global gate) so `submit` can stay public.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/AdminLogger.php';
require_once __DIR__ . '/../app/models/MemberRegistration.php';
require_once __DIR__ . '/../app/models/Member.php';
require_once __DIR__ . '/../app/models/Payment.php';
require_once __DIR__ . '/../app/helpers/AuthHelper.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

function reg_input(): array {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function reg_client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return is_string($ip) ? substr($ip, 0, 45) : '';
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $registrations = new MemberRegistration($db);

    switch ($action) {

        // ---- PUBLIC: a visitor submits a "create profile" request -----------
        case 'submit':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                exit;
            }
            $data = reg_input();
            $name = trim((string)($data['name'] ?? ''));
            $phone = trim((string)($data['phone'] ?? ''));
            $cnic = trim((string)($data['cnic'] ?? ''));
            $gender = strtolower(trim((string)($data['gender'] ?? '')));
            if ($gender !== 'men' && $gender !== 'women') {
                $gender = 'men'; // optional on the form; admin confirms the side at approval
            }

            // Only name, phone and CNIC are required (mirrors the gym's paper form).
            if ($name === '' || $phone === '' || $cnic === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Please enter your name, phone number and CNIC.']);
                exit;
            }
            $digits = preg_replace('/\D+/', '', $phone);
            if (strlen($digits) < 7 || strlen($digits) > 15) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Please enter a valid phone number.']);
                exit;
            }

            // Spam throttle: max 3 requests per phone/IP in 10 minutes.
            $ip = reg_client_ip();
            if ($registrations->recentCount($phone, $ip, 10) >= 3) {
                http_response_code(429);
                echo json_encode(['success' => false, 'message' => 'You have already sent a few requests. Please wait a little while before trying again.']);
                exit;
            }

            // All other admission-form fields are optional; keep them in details.
            $detailKeys = ['father_name', 'occupation', 'email', 'office_address', 'office_phone', 'blood_group',
                'shoulder', 'chest', 'bicep', 'forearm', 'waist', 'hip', 'thigh', 'calf', 'height', 'weight'];
            $details = [];
            foreach ($detailKeys as $k) {
                $v = trim((string)($data[$k] ?? ''));
                if ($v !== '') {
                    $details[$k] = mb_substr($v, 0, 120);
                }
            }

            $registrations->create([
                'gender' => $gender,
                'name' => $name,
                'phone' => $phone,
                'cnic' => $cnic,
                'dob' => $data['dob'] ?? '',
                'address' => $data['address'] ?? '',
                'note' => $data['note'] ?? '',
                'details' => $details,
                'source_ip' => $ip,
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Your request has been sent. The gym will set up your profile and give you your member code after payment.',
            ]);
            break;

        // ---- ADMIN/STAFF: list the queue ------------------------------------
        case 'list':
            AuthHelper::requireAdminOrStaff();
            $status = (string)($_GET['status'] ?? 'pending');
            $page = (int)($_GET['page'] ?? 1);
            $search = (string)($_GET['search'] ?? '');

            // Sectioned staff only see their side; admins see both.
            $allowed = AuthHelper::allowedSection(); // 'men' | 'women' | 'both'
            $genderFilter = ($allowed === 'men' || $allowed === 'women') ? $allowed : null;
            if (in_array(($_GET['gender'] ?? ''), ['men', 'women'], true) && ($genderFilter === null)) {
                $genderFilter = $_GET['gender'];
            }

            $result = $registrations->getAll($status, $genderFilter, $page, 20, $search);
            echo json_encode(['success' => true, 'pending_total' => $registrations->pendingCount($genderFilter)] + $result);
            break;

        // ---- ADMIN: suggest the next member code ----------------------------
        case 'next_code':
            AuthHelper::requireAdminOrStaff();
            AuthHelper::ensureAdminAction('Only admin can approve members');
            echo json_encode(['success' => true, 'next_code' => $registrations->suggestNextMemberCode()]);
            break;

        // ---- ADMIN: approve (create member + first payment) -----------------
        case 'approve':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                exit;
            }
            AuthHelper::requireAdminOrStaff();
            AuthHelper::ensureAdminAction('Only admin can approve members');

            $data = reg_input();
            $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid registration id']);
                exit;
            }
            $reg = $registrations->getById($id);
            if (!$reg) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Registration not found']);
                exit;
            }
            if ($reg['status'] !== 'pending') {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'This request has already been ' . $reg['status'] . '.']);
                exit;
            }

            // Admin confirms the side (men/women) at approval; fall back to the request.
            $gender = strtolower(trim((string)($data['gender'] ?? $reg['gender'])));
            $gender = ($gender === 'women') ? 'women' : 'men';
            $regDetails = [];
            if (!empty($reg['details'])) {
                $decoded = json_decode((string)$reg['details'], true);
                if (is_array($decoded)) {
                    $regDetails = $decoded;
                }
            }
            $memberCode = trim((string)($data['member_code'] ?? ''));
            if ($memberCode === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Member code is required']);
                exit;
            }

            // Unique across both gender tables.
            foreach (['men', 'women'] as $g) {
                $chk = $db->prepare("SELECT id FROM members_{$g} WHERE member_code = :c LIMIT 1");
                $chk->bindValue(':c', $memberCode, PDO::PARAM_STR);
                $chk->execute();
                if ($chk->fetch(PDO::FETCH_ASSOC)) {
                    http_response_code(409);
                    echo json_encode(['success' => false, 'message' => 'Member code "' . $memberCode . '" is already in use. Pick another.']);
                    exit;
                }
            }

            $admissionFee = round((float)($data['admission_fee'] ?? 0), 2);
            $monthlyFee = round((float)($data['monthly_fee'] ?? 0), 2);
            $lockerFee = round((float)($data['locker_fee'] ?? 0), 2);
            $ptfFee = round((float)($data['ptf_fee'] ?? 0), 2);
            $amountPaid = round((float)($data['amount_paid'] ?? 0), 2);
            $method_pay = trim((string)($data['payment_method'] ?? 'Cash')) ?: 'Cash';
            $joinDate = trim((string)($data['join_date'] ?? '')) ?: date('Y-m-d');
            $nextDue = trim((string)($data['next_fee_due_date'] ?? '')) ?: date('Y-m-d', strtotime('+1 month'));
            $membershipType = trim((string)($data['membership_type'] ?? 'Basic')) ?: 'Basic';

            $charges = round($admissionFee + $monthlyFee + $lockerFee + $ptfFee, 2);
            $remaining = max(0, round($charges - $amountPaid, 2));

            $reviewerId = (int)($_SESSION['user_id'] ?? 0);
            $reviewerName = (string)($_SESSION['name'] ?? ($_SESSION['username'] ?? 'Admin'));

            $registrations->ensureMemberExtraColumns($gender);

            $db->beginTransaction();
            try {
                $member = new Member($db, $gender);
                $memberId = $member->create([
                    'member_code' => $memberCode,
                    'name' => $reg['name'],
                    'phone' => $reg['phone'],
                    'address' => $reg['address'],
                    'email' => $regDetails['email'] ?? null,
                    'membership_type' => $membershipType,
                    'join_date' => $joinDate,
                    'admission_fee' => $admissionFee,
                    'monthly_fee' => $monthlyFee,
                    'locker_fee' => $lockerFee,
                    'next_fee_due_date' => $nextDue,
                    'total_due_amount' => $remaining,
                    'status' => 'active',
                ]);
                if (!$memberId) {
                    throw new RuntimeException('Could not create the member record.');
                }

                // Retain the extra application fields on the member.
                $upd = $db->prepare("UPDATE members_{$gender} SET cnic = :cnic, dob = :dob, emergency_name = :en, emergency_phone = :ep, ptf_fee = :ptf WHERE id = :id");
                $upd->bindValue(':cnic', $reg['cnic'] ?: null);
                $upd->bindValue(':dob', $reg['dob'] ?: null);
                $upd->bindValue(':en', $reg['emergency_name'] ?: null);
                $upd->bindValue(':ep', $reg['emergency_phone'] ?: null);
                $upd->bindValue(':ptf', $ptfFee);
                $upd->bindValue(':id', (int)$memberId, PDO::PARAM_INT);
                $upd->execute();

                // Record the first payment (if any was taken at approval).
                $paymentId = null;
                if ($amountPaid > 0) {
                    $payment = new Payment($db, $gender);
                    $paymentId = $payment->create([
                        'member_id' => (int)$memberId,
                        'amount' => $amountPaid,
                        'payment_date' => $joinDate,
                        'due_date' => $nextDue,
                        'total_due_amount' => $charges,
                        'remaining_amount' => $remaining,
                        'payment_type' => 'admission',
                        'payment_method' => $method_pay,
                        'received_by' => $reviewerName,
                        'status' => 'completed',
                    ]);
                }

                if (!$registrations->markApproved((int)$id, $memberCode, (int)$memberId, $reviewerId)) {
                    throw new RuntimeException('Could not finalize the registration.');
                }

                $db->commit();

                if (class_exists('SyncHelper')) {
                    try {
                        SyncHelper::markRecordForSync($db, 'members_' . $gender, (int)$memberId);
                        if ($paymentId) {
                            SyncHelper::markRecordForSync($db, 'payments_' . $gender, (int)$paymentId);
                        }
                    } catch (Throwable $e) {
                    }
                }

                try {
                    (new AdminLogger($db))->log('member_registration_approved', 'member_' . $gender, (int)$memberId, null, [
                        'registration_id' => (int)$id,
                        'member_code' => $memberCode,
                        'name' => $reg['name'],
                        'amount_paid' => $amountPaid,
                    ]);
                } catch (Throwable $e) {
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'Member created. Code ' . $memberCode . ' assigned.',
                    'member_id' => (int)$memberId,
                    'member_code' => $memberCode,
                    'gender' => $gender,
                ]);
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('registrations approve: ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Could not approve this member. ' . $e->getMessage()]);
            }
            break;

        // ---- ADMIN: reject --------------------------------------------------
        case 'reject':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                exit;
            }
            AuthHelper::requireAdminOrStaff();
            AuthHelper::ensureAdminAction('Only admin can reject members');

            $data = reg_input();
            $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid registration id']);
                exit;
            }
            $ok = $registrations->markRejected((int)$id, (int)($_SESSION['user_id'] ?? 0), (string)($data['reason'] ?? ''));
            if (!$ok) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'This request is no longer pending.']);
                exit;
            }
            try {
                (new AdminLogger($db))->log('member_registration_rejected', 'registration', (int)$id);
            } catch (Throwable $e) {
            }
            echo json_encode(['success' => true, 'message' => 'Request rejected.']);
            break;

        default:
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Throwable $e) {
    error_log('Registrations API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An unexpected server error occurred.']);
}
