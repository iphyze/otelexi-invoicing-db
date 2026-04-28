<?php
// routes/settings/uploadLogo.php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

/**
 * POST /settings/upload-logo
 * Upload company logo image. Returns logo_path.
 * Admin only.
 * Expects multipart/form-data
 */

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "status" => "Failed",
        "message" => "Method Not Allowed. Use POST."
    ]);
    exit;
}

// Authenticate user
 $userData = authenticateUser();
 $loggedInUserId = (int)$userData['id'];
 $loggedInUserName = $userData['email'];

// Only Admin allowed
if ($userData['role'] !== 'admin') {
    throw new Exception("Unauthorized: Only Admins can upload logos", 403);
}


// -------------------------------------------------------
// Configuration
// -------------------------------------------------------
 $maxFileSize = 2 * 1024 * 1024; // 2MB in bytes
 $allowedMimeTypes = ['image/jpeg', 'image/png'];
 $allowedExtensions = ['jpg', 'jpeg', 'png'];

// Define paths 
// Goes up 3 levels from /api/routes/settings/ to reach /otelex-server/
 $uploadDir = __DIR__ . '/../../../uploads/logos/'; 
 $baseUrlPath = '/uploads/logos/'; // What the frontend will use to access the image

try {
    // 1. Check if file was sent
    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        $errorMessage = "No file uploaded.";
        if (isset($_FILES['logo'])) {
            $errorCodes = [
                UPLOAD_ERR_INI_SIZE   => "The uploaded file exceeds the upload_max_filesize directive in php.ini.",
                UPLOAD_ERR_FORM_SIZE  => "The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.",
                UPLOAD_ERR_PARTIAL    => "The uploaded file was only partially uploaded.",
                UPLOAD_ERR_NO_FILE    => "No file was uploaded.",
                UPLOAD_ERR_NO_TMP_DIR => "Missing a temporary folder.",
                UPLOAD_ERR_CANT_WRITE => "Failed to write file to disk."
            ];
            $errorMessage = $errorCodes[$_FILES['logo']['error']] ?? "Unknown upload error.";
        }
        throw new Exception($errorMessage, 400);
    }

    $file = $_FILES['logo'];

    // 2. Validate file size (2MB max)
    if ($file['size'] > $maxFileSize) {
        throw new Exception("File size exceeds the 2MB limit. Your file is " . round($file['size'] / 1024 / 1024, 2) . "MB.", 400);
    }

    // 3. Validate MIME type and Extension
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($mimeType, $allowedMimeTypes) || !in_array($extension, $allowedExtensions)) {
        throw new Exception("Invalid file type. Only JPG and PNG images are allowed.", 400);
    }

    // 4. Create upload directory if it doesn't exist
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            throw new Exception("Failed to create upload directory. Check folder permissions.", 500);
        }
    }

    // 5. Generate unique filename to prevent overwriting
    $newFileName = 'logo_' . uniqid() . '.' . $extension;
    $destination = $uploadDir . $newFileName;

    // 6. Move uploaded file to permanent location
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new Exception("Failed to save the uploaded file to the server.", 500);
    }

    $relativeUrlPath = $baseUrlPath . $newFileName;

    // 7. Delete the OLD logo from the server to save space
    $stmt = mysqli_prepare($conn, "SELECT logo_path FROM company_settings LIMIT 1");
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $currentSettings = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if ($currentSettings && !empty($currentSettings['logo_path'])) {
        // UPDATED: Go up 3 levels to match the new upload directory
        $oldFilePath = __DIR__ . '/../../../' . $currentSettings['logo_path'];
        
        if (file_exists($oldFilePath) && $oldFilePath !== $destination) {
            unlink($oldFilePath);
        }
    }

    // 8. Update database with the new path
    $updateStmt = mysqli_prepare($conn, "UPDATE company_settings SET logo_path = ? LIMIT 1");
    mysqli_stmt_bind_param($updateStmt, "s", $relativeUrlPath);
    
    if (!mysqli_stmt_execute($updateStmt)) {
        // If DB update fails, remove the file we just uploaded
        unlink($destination);
        throw new Exception("Database update failed: " . mysqli_stmt_error($updateStmt), 500);
    }
    mysqli_stmt_close($updateStmt);

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Logo uploaded successfully.",
        "data" => [
            "logo_path" => $relativeUrlPath
        ]
    ]);

} catch (Exception $e) {
    // FIXED: Now it properly returns the correct HTTP status code (403 for auth, 400 for bad file, etc.)
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
    exit;
}
?>