<?php
/**
 * Profile Modal Component
 * Include this file in parent.php and hospital.php
 * Usage: <?php include 'profile_modal.php'; ?>
 */

// This file provides a reusable profile modal component
// It should be included in pages that need profile editing functionality
?>

<!-- Profile Modal CSS -->
<style>
    .profile-modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
    }

    .profile-modal.show {
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .profile-modal-content {
        background-color: white;
        padding: 30px;
        border-radius: 10px;
        width: 90%;
        max-width: 500px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        animation: slideIn 0.3s ease;
    }

    @keyframes slideIn {
        from {
            transform: translateY(-50px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .profile-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        border-bottom: 2px solid #e5e7eb;
        padding-bottom: 15px;
    }

    .profile-modal-header h2 {
        margin: 0;
        color: #1f2937;
        font-size: 24px;
    }

    .profile-modal-close {
        background: none;
        border: none;
        font-size: 28px;
        cursor: pointer;
        color: #6b7280;
        transition: color 0.3s;
    }

    .profile-modal-close:hover {
        color: #1f2937;
    }

    .profile-form-group {
        margin-bottom: 16px;
    }

    .profile-form-group label {
        display: block;
        margin-bottom: 6px;
        font-weight: 600;
        color: #374151;
        font-size: 14px;
    }

    .profile-form-group input,
    .profile-form-group textarea {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 14px;
        font-family: inherit;
        transition: border-color 0.3s;
        box-sizing: border-box;
    }

    .profile-form-group input:focus,
    .profile-form-group textarea:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    .profile-form-group textarea {
        resize: vertical;
        min-height: 80px;
    }

    .profile-modal-actions {
        display: flex;
        gap: 10px;
        margin-top: 24px;
    }

    .profile-btn-save {
        flex: 1;
        background: #3b82f6;
        color: white;
        border: none;
        padding: 12px;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        font-size: 14px;
        transition: background 0.3s;
    }

    .profile-btn-save:hover {
        background: #2563eb;
    }

    .profile-btn-cancel {
        flex: 1;
        background: #e5e7eb;
        color: #374151;
        border: none;
        padding: 12px;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        font-size: 14px;
        transition: background 0.3s;
    }

    .profile-btn-cancel:hover {
        background: #d1d5db;
    }

    .profile-avatar-section {
        text-align: center;
        margin-bottom: 20px;
    }

    .profile-avatar-large {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        background: #e5e7eb;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 40px;
        color: #6b7280;
        margin-bottom: 10px;
    }

    .profile-role-badge {
        display: inline-block;
        background: #dbeafe;
        color: #1e40af;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        text-transform: capitalize;
    }

    .profile-message {
        padding: 12px;
        border-radius: 6px;
        margin-bottom: 16px;
        font-size: 13px;
    }

    .profile-message.success {
        background: #dcfce7;
        color: #166534;
        border: 1px solid #bbf7d0;
    }

    .profile-message.error {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #fecaca;
    }
</style>

<!-- Profile Modal HTML -->
<div id="profileModal" class="profile-modal">
    <div class="profile-modal-content">
        <div class="profile-modal-header">
            <h2>Edit Profile</h2>
            <button class="profile-modal-close" onclick="closeProfileModal()">&times;</button>
        </div>

        <div class="profile-avatar-section">
            <div class="profile-avatar-large" id="profileAvatarLarge"></div>
            <span class="profile-role-badge" id="profileRoleBadge"></span>
        </div>

        <div id="profileMessage"></div>

        <form id="profileForm" method="POST">
            <input type="hidden" name="update_profile" value="1">
            <input type="hidden" name="csrf_token" id="profileCsrfToken" value="">

            <div class="profile-form-group">
                <label for="profileName">Full Name</label>
                <input type="text" id="profileName" name="name" required>
            </div>

            <div class="profile-form-group">
                <label for="profileEmail">Email</label>
                <input type="email" id="profileEmail" name="email" required>
            </div>

            <div class="profile-form-group">
                <label for="profilePhone">Phone</label>
                <input type="tel" id="profilePhone" name="phone">
            </div>

            <div class="profile-form-group">
                <label for="profilePassword">New Password (leave blank to keep current)</label>
                <input type="password" id="profilePassword" name="password" placeholder="Enter new password or leave blank">
            </div>

            <div class="profile-modal-actions">
                <button type="submit" class="profile-btn-save">Save Changes</button>
                <button type="button" class="profile-btn-cancel" onclick="closeProfileModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Profile Modal JavaScript -->
<script>
    function openProfileModal(userType) {
        const modal = document.getElementById('profileModal');
        modal.classList.add('show');

        // Fetch current profile data
        fetch('profile_api.php?action=get_profile&type=' + userType)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('profileName').value = data.name || '';
                    document.getElementById('profileEmail').value = data.email || '';
                    document.getElementById('profilePhone').value = data.phone || '';
                    document.getElementById('profileAvatarLarge').textContent = data.name ? data.name.charAt(0).toUpperCase() : '?';
                    document.getElementById('profileRoleBadge').textContent = userType;
                    document.getElementById('profileCsrfToken').value = data.csrf_token || '';
                }
            })
            .catch(err => console.error('Error fetching profile:', err));
    }

    function closeProfileModal() {
        const modal = document.getElementById('profileModal');
        modal.classList.remove('show');
        document.getElementById('profileMessage').innerHTML = '';
    }

    // Close modal when clicking outside
    document.getElementById('profileModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeProfileModal();
        }
    });

    // Handle profile form submission
    document.getElementById('profileForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(this);

        fetch('profile_api.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            const messageDiv = document.getElementById('profileMessage');
            if (data.success) {
                messageDiv.className = 'profile-message success';
                messageDiv.textContent = data.message || 'Profile updated successfully!';
                setTimeout(() => {
                    location.reload();
                }, 2000);
            } else {
                messageDiv.className = 'profile-message error';
                messageDiv.textContent = data.message || 'Error updating profile';
            }
        })
        .catch(err => {
            console.error('Error:', err);
            document.getElementById('profileMessage').className = 'profile-message error';
            document.getElementById('profileMessage').textContent = 'An error occurred';
        });
    });
</script>
