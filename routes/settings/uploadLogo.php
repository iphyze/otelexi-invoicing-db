<?php
// routes/settings/uploadLogo.php
// Super Admin-only company logo upload with portable JPG/PNG validation.

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';

use Dotenv\Dotenv;

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    requireRole($user, [ROLE_SUPER_ADMIN], 'Only the Super Admin can upload the company logo.');
    Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();

    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the server upload size limit.',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the permitted form size.',
            UPLOAD_ERR_PARTIAL => 'The logo was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'Please select a logo image to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server temporary upload folder is unavailable.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file.',
        ];
        $code = isset($_FILES['logo']) ? (int) $_FILES['logo']['error'] : UPLOAD_ERR_NO_FILE;
        throw new Exception($errors[$code] ?? 'Logo upload failed.', 400);
    }

    $file = $_FILES['logo'];
    $maxFileSize = 2 * 1024 * 1024;
    $allowedMimeTypes = ['image/jpeg', 'image/png'];
    $allowedExtensions = ['jpg', 'jpeg', 'png'];
    if ((int) $file['size'] > $maxFileSize) {
        throw new Exception('Logo file size exceeds the 2MB limit.', 422);
    }

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) {
        throw new Exception('Invalid file type. Only JPG and PNG images are allowed.', 422);
    }

    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false || !in_array($imageInfo['mime'] ?? '', $allowedMimeTypes, true)) {
        throw new Exception('Invalid image file. Please upload a valid JPG or PNG image.', 422);
    }
    if (class_exists('finfo')) {
        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        if (!in_array($fileInfo->file($file['tmp_name']), $allowedMimeTypes, true)) {
            throw new Exception('Invalid file type. Only JPG and PNG images are allowed.', 422);
        }
    }

    $uploadDomain = rtrim((string) ($_ENV['UPLOAD'] ?? ''), '/');
    if ($uploadDomain === '') {
        throw new Exception('Logo public URL configuration is unavailable.', 500);
    }
    $uploadDir = __DIR__ . '/../../../uploads/logos/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new Exception('The logo upload directory could not be created.', 500);
    }

    $newFileName = 'logo_' . bin2hex(random_bytes(12)) . '.' . $extension;
    $destination = $uploadDir . $newFileName;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new Exception('The uploaded logo could not be saved.', 500);
    }
    $publicPath = $uploadDomain . '/uploads/logos/' . $newFileName;

    $current = $conn->query('SELECT logo_path FROM company_settings LIMIT 1')->fetch_assoc();
    $update = $conn->prepare('UPDATE company_settings SET logo_path = ? LIMIT 1');
    $update->bind_param('s', $publicPath);
    if (!$update->execute()) {
        @unlink($destination);
        throw new Exception('The uploaded logo could not be stored in settings.', 500);
    }
    $update->close();

    $oldPath = (string) ($current['logo_path'] ?? '');
    $oldName = basename((string) parse_url($oldPath, PHP_URL_PATH));
    $oldLocalFile = $oldName !== '' ? $uploadDir . $oldName : '';
    if ($oldLocalFile !== '' && is_file($oldLocalFile) && $oldLocalFile !== $destination) {
        @unlink($oldLocalFile);
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Logo uploaded successfully.',
        'data' => ['logo_path' => $publicPath],
    ]);
} catch (Throwable $e) {
    error_log('Logo Upload Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'failed',
        'message' => $code === 500 ? 'Logo upload failed. Please try again.' : $e->getMessage(),
    ]);
}
