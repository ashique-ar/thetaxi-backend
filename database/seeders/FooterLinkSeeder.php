<?php

namespace Database\Seeders;

use App\Models\FooterLink;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class FooterLinkSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear existing footer links
        FooterLink::truncate();

        // Social Media Links
        FooterLink::create([
            'title' => 'Facebook',
            'url' => 'https://facebook.com/casonsrentacar',
            'target' => '_blank',
            'icon' => 'fab fa-facebook-f',
            'description' => 'Follow us on Facebook',
            'footer_section' => 'social',
            'sort_order' => 1,
            'is_active' => true,
            'additional_attributes' => [
                'class' => 'social-link facebook',
                'rel' => 'noopener noreferrer',
                'aria-label' => 'Visit our Facebook page'
            ]
        ]);

        FooterLink::create([
            'title' => 'Instagram',
            'url' => 'https://instagram.com/casonsrentacar',
            'target' => '_blank',
            'icon' => 'fab fa-instagram',
            'description' => 'Follow us on Instagram',
            'footer_section' => 'social',
            'sort_order' => 2,
            'is_active' => true,
            'additional_attributes' => [
                'class' => 'social-link instagram',
                'rel' => 'noopener noreferrer',
                'aria-label' => 'Visit our Instagram page'
            ]
        ]);

        FooterLink::create([
            'title' => 'Twitter',
            'url' => 'https://twitter.com/casonsrentacar',
            'target' => '_blank',
            'icon' => 'fab fa-twitter',
            'description' => 'Follow us on Twitter',
            'footer_section' => 'social',
            'sort_order' => 3,
            'is_active' => true,
            'additional_attributes' => [
                'class' => 'social-link twitter',
                'rel' => 'noopener noreferrer',
                'aria-label' => 'Visit our Twitter page'
            ]
        ]);

        FooterLink::create([
            'title' => 'LinkedIn',
            'url' => 'https://linkedin.com/company/casons-rent-a-car',
            'target' => '_blank',
            'icon' => 'fab fa-linkedin-in',
            'description' => 'Connect with us on LinkedIn',
            'footer_section' => 'social',
            'sort_order' => 4,
            'is_active' => true,
            'additional_attributes' => [
                'class' => 'social-link linkedin',
                'rel' => 'noopener noreferrer',
                'aria-label' => 'Visit our LinkedIn page'
            ]
        ]);

        FooterLink::create([
            'title' => 'YouTube',
            'url' => 'https://youtube.com/@casonsrentacar',
            'target' => '_blank',
            'icon' => 'fab fa-youtube',
            'description' => 'Subscribe to our YouTube channel',
            'footer_section' => 'social',
            'sort_order' => 5,
            'is_active' => true,
            'additional_attributes' => [
                'class' => 'social-link youtube',
                'rel' => 'noopener noreferrer',
                'aria-label' => 'Visit our YouTube channel'
            ]
        ]);

        // Legal Links
        FooterLink::create([
            'title' => 'Privacy Policy',
            'url' => '/privacy-policy',
            'target' => '_self',
            'description' => 'Our privacy policy and data protection information',
            'footer_section' => 'legal',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        FooterLink::create([
            'title' => 'Terms & Conditions',
            'url' => '/terms-conditions',
            'target' => '_self',
            'description' => 'Terms and conditions of our services',
            'footer_section' => 'legal',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        FooterLink::create([
            'title' => 'Cookie Policy',
            'url' => '/cookie-policy',
            'target' => '_self',
            'description' => 'Information about cookies and tracking',
            'footer_section' => 'legal',
            'sort_order' => 3,
            'is_active' => true,
        ]);

        FooterLink::create([
            'title' => 'Refund Policy',
            'url' => '/refund-policy',
            'target' => '_self',
            'description' => 'Booking cancellation and refund policy',
            'footer_section' => 'legal',
            'sort_order' => 4,
            'is_active' => true,
        ]);

        FooterLink::create([
            'title' => 'Damage Policy',
            'url' => '/damage-policy',
            'target' => '_self',
            'description' => 'Vehicle damage and liability policy',
            'footer_section' => 'legal',
            'sort_order' => 5,
            'is_active' => true,
        ]);

        // Contact Information Links
        FooterLink::create([
            'title' => 'Head Office',
            'url' => '#',
            'target' => '_self',
            'icon' => 'fas fa-phone',
            'description' => 'Call our head office',
            'footer_section' => 'contact',
            'sort_order' => 1,
            'is_active' => true,
            'additional_attributes' => [
                'data-setting' => 'company_phone'
            ]
        ]);

        FooterLink::create([
            'title' => '24/7 Hotline',
            'url' => '#',
            'target' => '_self',
            'icon' => 'fas fa-phone-alt',
            'description' => '24/7 emergency assistance',
            'footer_section' => 'contact',
            'sort_order' => 2,
            'is_active' => true,
            'additional_attributes' => [
                'data-setting' => 'emergency_contact',
                'class' => 'hotline-number'
            ]
        ]);

        FooterLink::create([
            'title' => 'Email Support',
            'url' => 'mailto:info@casonsrentacar.lk',
            'target' => '_self',
            'icon' => 'fas fa-envelope',
            'description' => 'Send us an email',
            'footer_section' => 'contact',
            'sort_order' => 3,
            'is_active' => true,
            'additional_attributes' => [
                'data-email' => 'info@casonsrentacar.lk'
            ]
        ]);

        FooterLink::create([
            'title' => 'WhatsApp',
            'url' => '#',
            'target' => '_blank',
            'icon' => 'fab fa-whatsapp',
            'description' => 'Chat with us on WhatsApp',
            'footer_section' => 'contact',
            'sort_order' => 4,
            'is_active' => true,
            'additional_attributes' => [
                'class' => 'whatsapp-link',
                'rel' => 'noopener noreferrer',
                'data-setting' => 'company_whatsapp'
            ]
        ]);

        FooterLink::create([
            'title' => 'Live Chat',
            'url' => '#',
            'target' => '_self',
            'icon' => 'fas fa-comments',
            'description' => 'Start live chat support',
            'footer_section' => 'contact',
            'sort_order' => 5,
            'is_active' => true,
            'additional_attributes' => [
                'class' => 'live-chat-trigger',
                'data-action' => 'open-chat'
            ]
        ]);

        // Additional Quick Links
        FooterLink::create([
            'title' => 'Branch Locations',
            'url' => '/locations',
            'target' => '_self',
            'icon' => 'fas fa-map-marker-alt',
            'description' => 'Find our branch locations',
            'footer_section' => 'links',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        FooterLink::create([
            'title' => 'Special Offers',
            'url' => '/offers',
            'target' => '_self',
            'icon' => 'fas fa-tags',
            'description' => 'Current promotions and special offers',
            'footer_section' => 'links',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        FooterLink::create([
            'title' => 'Corporate Booking',
            'url' => '/corporate',
            'target' => '_self',
            'icon' => 'fas fa-building',
            'description' => 'Corporate and business bookings',
            'footer_section' => 'links',
            'sort_order' => 3,
            'is_active' => true,
        ]);

        FooterLink::create([
            'title' => 'Driver Application',
            'url' => '/join-as-driver',
            'target' => '_self',
            'icon' => 'fas fa-user-plus',
            'description' => 'Join our team as a driver',
            'footer_section' => 'links',
            'sort_order' => 4,
            'is_active' => true,
        ]);

        FooterLink::create([
            'title' => 'Partner With Us',
            'url' => '/partnerships',
            'target' => '_self',
            'icon' => 'fas fa-handshake',
            'description' => 'Business partnership opportunities',
            'footer_section' => 'links',
            'sort_order' => 5,
            'is_active' => true,
        ]);

        FooterLink::create([
            'title' => 'FAQ',
            'url' => '/faq',
            'target' => '_self',
            'icon' => 'fas fa-question-circle',
            'description' => 'Frequently asked questions',
            'footer_section' => 'links',
            'sort_order' => 6,
            'is_active' => true,
        ]);

        $this->command->info('Footer links seeded successfully!');
    }
}
