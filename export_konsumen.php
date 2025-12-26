<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Manually include required PhpSpreadsheet files
require_once __DIR__ . '/vendor/autoload.php';

// Check if required classes exist
if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
    die('PhpSpreadsheet library not found. Please run "composer require phpoffice/phpspreadsheet"');
}

require_once __DIR__ . '/config/config.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

try {
    // Get database connection
    $conn = getConnection();

    // Get filter parameters
    $search = $_GET['search'] ?? '';
    $status = $_GET['status'] ?? '';

    // Build query
    $query = "SELECT * FROM konsumen WHERE 1=1";
    $params = [];
    $types = '';

    if (!empty($search)) {
        $query .= " AND (nama_konsumen LIKE ? OR perusahaan LIKE ? OR email LIKE ? OR no_hp LIKE ?)";
        $searchTerm = "%$search%";
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        $types .= 'ssss';
    }

    $query .= " ORDER BY nama_konsumen ASC";

    // Prepare and execute query
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $customers = $result->fetch_all(MYSQLI_ASSOC);

    // Create new Spreadsheet object
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Set document properties
    $spreadsheet->getProperties()
        ->setCreator('CV. Panca Indra Kemasan')
        ->setTitle('Data Konsumen')
        ->setSubject('Data Konsumen')
        ->setDescription('Data konsumen CV. Panca Indra Kemasan');

    // Set headers
    $headers = [
        'No',
        'Nama Konsumen',
        'Perusahaan',
        'Alamat',
        'No. HP',
        'Email',
        'Tanggal Dibuat',
        'Terakhir Diupdate'
    ];
    $sheet->fromArray($headers, NULL, 'A1');

    // Set data
    $row = 2;
    foreach ($customers as $index => $customer) {
        $sheet->fromArray([
            $index + 1,
            $customer['nama_konsumen'] ?? '',
            $customer['perusahaan'] ?? '',
            $customer['alamat'] ?? '',
            $customer['no_hp'] ?? '',
            $customer['email'] ?? '',
            $customer['created_at'] ?? '',
            $customer['updated_at'] ?? ''
        ], NULL, "A$row");
        $row++;
    }

    // Style the header
    $sheet->getStyle('A1:H1')->getFont()->setBold(true);
    $sheet->getStyle('A1:H1')->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFDDDDDD');

    // Auto size columns
    foreach (range('A', 'H') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Set headers for download
    $filename = 'data_konsumen_' . date('Y-m-d') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    // Save to php output
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;

} catch (Exception $e) {
    die('Error: ' . $e->getMessage());
}
