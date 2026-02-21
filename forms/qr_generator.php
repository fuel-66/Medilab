<?php
/**
 * QR Code Generator Helper
 * Uses Google Charts API for QR generation (no external library needed)
 * Can be swapped with local libraries like phpqrcode or endroid/qr-code
 */

class QRCodeGenerator {
    
    // Method 1: Using Google Charts API (requires internet)
    // Recommended for initial testing
    public static function generateQRUrlGoogle($data, $size = 300) {
        $encoded_data = urlencode($data);
        return "https://chart.googleapis.com/chart?chs={$size}x{$size}&chld=L|0&cht=qr&chl={$encoded_data}";
    }
    
    // Method 2: Generate QR code as PNG file using built-in functions
    // This uses a pure PHP implementation (slower but no dependencies)
    public static function generateQRImage($data, $filepath, $size = 300) {
        try {
            // For production, you should use a library like:
            // composer require endroid/qr-code
            // or sonata-project/google-charts
            
            // For now, use Google API to fetch and save locally
            $qr_url = self::generateQRUrlGoogle($data, $size);
            $image_data = @file_get_contents($qr_url);
            
            if ($image_data === false) {
                return [
                    'success' => false,
                    'error' => 'Failed to generate QR code via Google Charts API'
                ];
            }
            
            // Create directory if not exists
            $directory = dirname($filepath);
            if (!is_dir($directory)) {
                @mkdir($directory, 0755, true);
            }
            
            // Save image file
            if (file_put_contents($filepath, $image_data) === false) {
                return [
                    'success' => false,
                    'error' => 'Failed to save QR code image to file'
                ];
            }
            
            return [
                'success' => true,
                'filepath' => $filepath,
                'url' => str_replace($_SERVER['DOCUMENT_ROOT'], '', $filepath),
                'base64' => base64_encode($image_data)
            ];
        } catch (Exception $e) {
            error_log("QRCodeGenerator::generateQRImage error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Exception: ' . $e->getMessage()
            ];
        }
    }
    
    // Method 3: Get Base64 QR code for inline display (no file needed)
    public static function generateQRBase64($data, $size = 300) {
        try {
            $qr_url = self::generateQRUrlGoogle($data, $size);
            $image_data = @file_get_contents($qr_url);
            
            if ($image_data === false) {
                return [
                    'success' => false,
                    'error' => 'Failed to generate QR code'
                ];
            }
            
            return [
                'success' => true,
                'base64' => base64_encode($image_data),
                'mime' => 'image/png'
            ];
        } catch (Exception $e) {
            error_log("QRCodeGenerator::generateQRBase64 error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Exception: ' . $e->getMessage()
            ];
        }
    }
}

/**
 * API Integration Points for Future Payment Gateways
 * 
 * STRIPE Integration:
 * - API_KEY in environment
 * - Create Payment Intent before redirecting to checkout
 * - Webhook to confirm payment
 * 
 * JAZZCASH Integration (Pakistan):
 * - Merchant ID, Password, PPID
 * - Generate secure hash for request
 * - IPN callback for status updates
 * 
 * RAZORPAY Integration (India):
 * - Key ID and Secret
 * - Create Order via API
 * - Verify payment signature on callback
 */

?>
