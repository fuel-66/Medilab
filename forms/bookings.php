<?php
require_once 'config.php';
require_once 'functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirect('login.php');
}

$page_title = "Bookings | Madical";
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

// Handle search and filters
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$status = isset($_GET['status']) ? sanitize($_GET['status']) : '';

// Build query based on user role
if ($user_role === 'admin') {
    $sql = "SELECT b.*, p.name as parent_name, h.name as hospital_name 
            FROM bookings b 
            LEFT JOIN parents p ON b.parent_id = p.id 
            LEFT JOIN hospitals h ON b.hospital_id = h.id 
            WHERE (p.name LIKE ? OR h.name LIKE ?)";
    
    $params = ["%$search%", "%$search%"];
    
    if ($status) {
        $sql .= " AND b.status = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY b.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
} elseif ($user_role === 'hospital') {
    $sql = "SELECT b.*, p.name as parent_name 
            FROM bookings b 
            LEFT JOIN parents p ON b.parent_id = p.id 
            WHERE b.hospital_id = ? AND p.name LIKE ?";
    
    $params = [$user_id, "%$search%"];
    
    if ($status) {
        $sql .= " AND b.status = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY b.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
} else {
    // Parent
    $sql = "SELECT b.*, h.name as hospital_name 
            FROM bookings b 
            LEFT JOIN hospitals h ON b.hospital_id = h.id 
            WHERE b.parent_id = ? AND h.name LIKE ?";
    
    $params = [$user_id, "%$search%"];
    
    if ($status) {
        $sql .= " AND b.status = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY b.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'header.php';
?>

<div class="content-area">
    <div class="content-header">
        <h2 class="content-title">Bookings Management</h2>
        <div style="display: flex; gap: 1rem;">
            <form method="GET" action="" style="display: flex; gap: 0.5rem;">
                <input type="text" name="search" class="form-control" placeholder="Search bookings..." value="<?php echo $search; ?>">
                <select name="status" class="form-select" style="width: auto;">
                    <option value="">All Status</option>
                    <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="approved" <?php echo $status == 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="completed" <?php echo $status == 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="cancelled" <?php echo $status == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i>
                </button>
            </form>
            <a href="booking-add.php" class="btn btn-primary">
                <i class="fas fa-plus"></i>
                New Booking
            </a>
        </div>
    </div>
    
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Booking ID</th>
                    <?php if ($user_role === 'admin'): ?>
                    <th>Parent</th>
                    <th>Hospital</th>
                    <?php elseif ($user_role === 'hospital'): ?>
                    <th>Parent</th>
                    <?php else: ?>
                    <th>Hospital</th>
                    <?php endif; ?>
                    <th>Vaccine</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($bookings) > 0): ?>
                    <?php foreach ($bookings as $booking): ?>
                    <tr>
                        <td>#<?php echo str_pad($booking['id'], 6, '0', STR_PAD_LEFT); ?></td>
                        <?php if ($user_role === 'admin'): ?>
                        <td><?php echo $booking['parent_name']; ?></td>
                        <td><?php echo $booking['hospital_name']; ?></td>
                        <?php elseif ($user_role === 'hospital'): ?>
                        <td><?php echo $booking['parent_name']; ?></td>
                        <?php else: ?>
                        <td><?php echo $booking['hospital_name']; ?></td>
                        <?php endif; ?>
                        <td><?php echo $booking['vaccine_type']; ?></td>
                        <td>
                            <div style="font-weight: 600;"><?php echo formatDate($booking['booking_date']); ?></div>
                            <div style="color: var(--gray); font-size: 0.9rem;"><?php echo $booking['booking_time']; ?></div>
                        </td>
                        <td>
                            <span class="status-badge status-<?php echo $booking['status']; ?>">
                                <?php echo ucfirst($booking['status']); ?>
                            </span>
                        </td>
                        <td>
                            <div style="display: flex; gap: 0.5rem;">
                                <a href="booking-details.php?id=<?php echo $booking['id']; ?>" class="btn" style="padding: 0.5rem 1rem;">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if (($user_role === 'admin' || $user_role === 'hospital') && $booking['status'] == 'pending'): ?>
                                <a href="booking-approve.php?id=<?php echo $booking['id']; ?>" class="btn" style="padding: 0.5rem 1rem; background: rgba(16, 185, 129, 0.1); color: var(--success);">
                                    <i class="fas fa-check"></i>
                                </a>
                                <a href="booking-reject.php?id=<?php echo $booking['id']; ?>" class="btn" style="padding: 0.5rem 1rem; background: rgba(239, 68, 68, 0.1); color: var(--error);">
                                    <i class="fas fa-times"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="<?php echo $user_role === 'admin' ? 7 : ($user_role === 'hospital' ? 6 : 6); ?>" style="text-align: center; padding: 3rem; color: var(--gray);">
                            <i class="fas fa-calendar" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <p>No bookings found</p>
                            <?php if ($search || $status): ?>
                                <p>Try adjusting your search terms or filters</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php if (count($bookings) > 0): ?>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 2rem; padding-top: 1rem; border-top: 1px solid rgba(226, 232, 240, 0.8);">
        <div style="color: var(--gray);">
            Showing <?php echo count($bookings); ?> bookings
        </div>
        <div style="display: flex; gap: 0.5rem;">
            <button class="btn" disabled>Previous</button>
            <button class="btn btn-primary">1</button>
            <button class="btn">2</button>
            <button class="btn">3</button>
            <button class="btn">Next</button>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>