<?php
/**
 * Email Configuration for CITE ProTrak
 * Update these settings with your actual email credentials
 */

class EmailConfig {
    // SMTP Configuration
    const SMTP_HOST = 'smtp.gmail.com';
    const SMTP_PORT = 587;
    const SMTP_SECURE = 'tls';
    
    // Email Credentials (Update these with your actual credentials)
    const SMTP_USERNAME = 'noreplyprotrak@gmail.com'; // Replace with your Gmail
    const SMTP_PASSWORD = 'gfft rebt bvnp xmot';    // Replace with your Gmail App Password
    
    // Sender Information
    const FROM_EMAIL = 'noreplyprotrak@gmail.com';    // Replace with your Gmail
    const FROM_NAME = 'CITE ProTrak System';
    
    // Email Templates
    public static function getOTPEmailTemplate($otp_code, $expires_at) {
        $template = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>CITE ProTrak - Email Verification</title>
            <style>
                body { font-family: Arial, sans-serif; background-color: #f5f5f5; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background-color: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #618264, #79AC78); color: white; padding: 30px; text-align: center; }
                .logo { width: 80px; height: 80px; background-color: white; border-radius: 10px; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center; }
                .content { padding: 40px 30px; text-align: center; }
                .otp-code { font-size: 36px; font-weight: bold; color: #618264; background-color: #f8f9fa; padding: 20px; border-radius: 10px; margin: 20px 0; letter-spacing: 8px; }
                .footer { background-color: #f8f9fa; padding: 20px; text-align: center; color: #666; font-size: 14px; }
                .warning { background-color: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 15px; border-radius: 5px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <div class="logo">
                        <strong style="color: #618264; font-size: 24px;">CP</strong>
                    </div>
                    <h1 style="margin: 0; font-size: 28px;">CITE ProTrak</h1>
                    <p style="margin: 10px 0 0 0; opacity: 0.9;">Project Tracking & Monitoring System</p>
                </div>
                
                <div class="content">
                    <h2 style="color: #333; margin-bottom: 20px;">Email Verification Required</h2>
                    <p style="color: #666; font-size: 16px; line-height: 1.6;">
                        Thank you for registering with CITE ProTrak! To complete your account setup, 
                        please verify your email address using the OTP code below:
                    </p>
                    
                    <div class="otp-code">' . $otp_code . '</div>
                    
                    <div class="warning">
                        <strong>⚠️ Important:</strong> This OTP will expire on <strong>' . date('M j, Y \a\t g:i A', strtotime($expires_at)) . '</strong>
                    </div>
                    
                    <p style="color: #666; font-size: 14px; margin-top: 30px;">
                        If you didn\'t request this verification, please ignore this email.
                    </p>
                </div>
                
                <div class="footer">
                    <p style="margin: 0;">© ' . date('Y') . ' CITE ProTrak System. All rights reserved.</p>
                    <p style="margin: 5px 0 0 0;">This is an automated message, please do not reply.</p>
                </div>
            </div>
        </body>
        </html>';
        
        return $template;
    }
}
?>
