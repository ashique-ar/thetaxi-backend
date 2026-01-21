<?php

namespace Database\Seeders;

use App\Models\InquiryForm;
use App\Models\InquiryServicePage;
use App\Models\Service\ServiceType;
use Illuminate\Database\Seeder;

class CorporateTransferInquirySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $serviceType = ServiceType::where('code', 'corporate')->first();

        $form = InquiryForm::withInactive()
            ->where('slug', 'corporate-transfer-inquiry')
            ->first();

        if (!$form) {
            $form = InquiryForm::create([
                'name' => 'Corporate Transfer Inquiry',
                'slug' => 'corporate-transfer-inquiry',
                'description' => 'Inquiry form for corporate transport services.',
                'submit_label' => 'Submit Enquiry',
                'success_message' => 'Thank you for your inquiry! Our corporate team will contact you within 24 hours.',
                'settings' => [
                    'confirmation_intro' => 'Thank you for contacting TheTaxi about corporate transport services. Our corporate team will review your requirements and respond shortly.',
                    'contact_name_field' => 'contact_person',
                    'contact_email_field' => 'email',
                    'contact_phone_field' => 'phone',
                    'phone_initial_country' => 'lk',
                    'phone_preferred_countries' => ['lk', 'us', 'gb', 'au'],
                ],
                'is_active' => true,
            ]);
        } else {
            $form->update([
                'name' => 'Corporate Transfer Inquiry',
                'description' => 'Inquiry form for corporate transport services.',
                'submit_label' => 'Submit Enquiry',
                'success_message' => 'Thank you for your inquiry! Our corporate team will contact you within 24 hours.',
                'settings' => array_merge($form->settings ?? [], [
                    'confirmation_intro' => 'Thank you for contacting TheTaxi about corporate transport services. Our corporate team will review your requirements and respond shortly.',
                    'contact_name_field' => 'contact_person',
                    'contact_email_field' => 'email',
                    'contact_phone_field' => 'phone',
                    'phone_initial_country' => 'lk',
                    'phone_preferred_countries' => ['lk', 'us', 'gb', 'au'],
                ]),
                'is_active' => true,
            ]);
        }

        $fields = [
            [
                'name' => 'company_name',
                'label' => 'Company Name',
                'type' => 'text',
                'icon' => 'bi bi-building',
                'placeholder' => 'Company Name',
                'is_required' => true,
                'validation_rules' => 'string|max:255',
                'width' => 'half',
            ],
            [
                'name' => 'contact_person',
                'label' => 'Contact Person',
                'type' => 'text',
                'icon' => 'bi bi-person',
                'placeholder' => 'Contact Person',
                'is_required' => true,
                'validation_rules' => 'string|max:255',
                'width' => 'half',
            ],
            [
                'name' => 'email',
                'label' => 'Email Address',
                'type' => 'email',
                'icon' => 'bi bi-envelope',
                'placeholder' => 'Email Address',
                'is_required' => true,
                'validation_rules' => 'email|max:255',
                'width' => 'half',
            ],
            [
                'name' => 'phone',
                'label' => 'Mobile Number',
                'type' => 'tel',
                'icon' => 'bi bi-telephone',
                'placeholder' => 'Mobile Number',
                'is_required' => true,
                'validation_rules' => 'string|max:20',
                'width' => 'half',
            ],
            [
                'name' => 'service_type_select',
                'label' => 'Type of Services',
                'type' => 'select',
                'icon' => 'bi bi-briefcase',
                'is_required' => true,
                'validation_rules' => 'string|in:airport_transfer,corporate_event,employee_shuttle,client_meeting,other',
                'options' => [
                    ['value' => 'airport_transfer', 'label' => 'Airport Transfer'],
                    ['value' => 'corporate_event', 'label' => 'Corporate Event'],
                    ['value' => 'employee_shuttle', 'label' => 'Employee Shuttle'],
                    ['value' => 'client_meeting', 'label' => 'Client Meeting'],
                    ['value' => 'other', 'label' => 'Other'],
                ],
                'width' => 'half',
            ],
            [
                'name' => 'other_service_type',
                'label' => 'Specify the Service Type',
                'type' => 'text',
                'icon' => 'bi bi-briefcase',
                'placeholder' => 'Specify the service type',
                'is_required' => false,
                'validation_rules' => 'required_if:service_type_select,other|string|max:255',
                'conditional_logic' => [
                    'field' => 'service_type_select',
                    'operator' => 'equals',
                    'value' => 'other',
                ],
                'width' => 'half',
            ],
            [
                'name' => 'employee_strength',
                'label' => 'Employee Strength',
                'type' => 'select',
                'icon' => 'bi bi-people',
                'is_required' => true,
                'validation_rules' => 'string|in:1-10,11-50,51-100,101-500,500+',
                'options' => [
                    ['value' => '1-10', 'label' => '1-10 Employees'],
                    ['value' => '11-50', 'label' => '11-50 Employees'],
                    ['value' => '51-100', 'label' => '51-100 Employees'],
                    ['value' => '101-500', 'label' => '101-500 Employees'],
                    ['value' => '500+', 'label' => '500+ Employees'],
                ],
                'width' => 'half',
            ],
            [
                'name' => 'city_name',
                'label' => 'City Name',
                'type' => 'text',
                'icon' => 'bi bi-geo-alt',
                'placeholder' => 'City Name',
                'is_required' => true,
                'validation_rules' => 'string|max:255',
                'width' => 'half',
            ],
            [
                'name' => 'requirements',
                'label' => 'Service Requirements',
                'type' => 'textarea',
                'icon' => 'bi bi-chat-left-text',
                'placeholder' => 'Describe your corporate transport requirements...',
                'is_required' => true,
                'validation_rules' => 'string|max:1000',
                'width' => 'full',
            ],
        ];

        $existingFields = $form->fields()->withInactive()->get()->keyBy('name');
        foreach ($fields as $index => $fieldData) {
            $payload = array_merge($fieldData, ['sort_order' => $index + 1]);
            $field = $existingFields->get($fieldData['name']);

            if ($field) {
                $field->update($payload);
            } else {
                $form->fields()->create($payload);
            }
        }

        $page = InquiryServicePage::withInactive()
            ->where('slug', 'corporate-transfers')
            ->first();

        $content = [
            'sections' => [
                [
                    'type' => 'hero',
                    'data' => [
                        'banner_image' => 'assets/img/home4/home4-banner-img.jpg',
                        'heading' => 'Corporate Transport Solutions',
                        'subheading' => 'Professional transportation services tailored for your business needs',
                        'form_id' => $form->id,
                    ],
                ],
                [
                    'type' => 'contact_info',
                    'data' => [
                        'kicker' => 'Get In Touch',
                        'heading' => 'Contact Our Corporate Team',
                        'description' => 'Have questions or need immediate assistance? Our corporate transport specialists are here to help.',
                        'contacts' => [
                            [
                                'name' => 'Zufer Ahamed',
                                'title' => 'Managing Director',
                                'email' => 'zufer@thetaxi.lk',
                                'phone' => '+94715487487',
                                'availability' => 'Monday - Friday, 9:00 AM - 6:00 PM',
                            ],
                        ],
                        'office_hours' => [
                            'weekdays' => '9:00 AM - 6:00 PM',
                            'saturday' => '9:00 AM - 2:00 PM',
                            'sunday' => 'Closed',
                        ],
                        'emergency_hotline' => '+94 11 234 5678',
                        'show_location' => true,
                        'location' => [
                            'address' => 'TheTaxi Corporate Office, Colombo 03, Sri Lanka',
                            'map_embed' => '',
                        ],
                    ],
                ],
                [
                    'type' => 'features',
                    'data' => [
                        'heading' => 'Why Choose Our Corporate Transport?',
                        'description' => 'Professional, reliable, and efficient transportation solutions for your business',
                        'vector' => 'assets/img/home4/vector/feature-card-vector.svg',
                        'items' => [
                            [
                                'icon' => 'assets/img/home4/icon/feature-icon1.svg',
                                'title' => 'Executive Fleet',
                                'description' => 'Premium vehicles maintained to the highest standards for your corporate image and comfort.',
                            ],
                            [
                                'icon' => 'assets/img/home4/icon/feature-icon2.svg',
                                'title' => 'Professional Chauffeurs',
                                'description' => 'Experienced, uniformed drivers who understand corporate etiquette and punctuality.',
                            ],
                            [
                                'icon' => 'assets/img/home4/icon/feature-icon3.svg',
                                'title' => 'Account Management',
                                'description' => 'Dedicated account managers and monthly billing options for seamless corporate integration.',
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'services',
                    'data' => [
                        'kicker' => 'Corporate Solutions',
                        'heading' => 'Tailored Business Transport',
                        'description' => 'Our corporate transport services are designed to meet the unique needs of businesses, from executive travel to employee shuttles and client transportation.',
                        'features' => [
                            'Executive airport transfers',
                            'Corporate event transportation',
                            'Employee shuttle services',
                            'Client meeting transportation',
                            'Monthly billing and reporting',
                            '24/7 customer support',
                        ],
                        'image' => 'assets/img/home4/package-img.jpg',
                    ],
                ],
                [
                    'type' => 'benefits',
                    'data' => [
                        'kicker' => 'Corporate Benefits',
                        'heading' => 'What Makes Us Different',
                        'items' => [
                            [
                                'icon' => 'bi bi-clock-history',
                                'title' => 'Punctuality Guaranteed',
                                'description' => 'On-time arrivals with real-time tracking and proactive communication.',
                            ],
                            [
                                'icon' => 'bi bi-shield-check',
                                'title' => 'Secure & Safe',
                                'description' => 'Fully licensed, insured vehicles with background-checked professional drivers.',
                            ],
                            [
                                'icon' => 'bi bi-graph-up-arrow',
                                'title' => 'Cost Effective',
                                'description' => 'Competitive rates with volume discounts and transparent pricing structure.',
                            ],
                            [
                                'icon' => 'bi bi-headset',
                                'title' => '24/7 Support',
                                'description' => 'Round-the-clock customer support and emergency assistance when needed.',
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'faq',
                    'data' => [
                        'kicker' => 'Frequently Asked Questions',
                        'heading' => 'Corporate Transport Questions',
                        'items' => [
                            [
                                'question' => 'How do I set up a corporate account?',
                                'answer' => 'Setting up a corporate account is simple. Submit an enquiry through our form, and our corporate sales team will contact you within 24 hours to discuss your requirements and set up your account with preferred payment terms.',
                            ],
                            [
                                'question' => 'What types of vehicles do you offer for corporate clients?',
                                'answer' => 'We offer a premium fleet including executive sedans, luxury SUVs, people carriers for groups, and minibuses for larger corporate events. All vehicles are less than 3 years old and maintained to the highest standards.',
                            ],
                            [
                                'question' => 'Do you provide monthly billing?',
                                'answer' => 'Yes, we offer monthly billing with detailed journey reports for all corporate accounts. Invoices include trip details, passenger information, and cost center allocation for easy expense management.',
                            ],
                            [
                                'question' => 'Can employees book directly?',
                                'answer' => 'Yes, we can provide your employees with access to our corporate booking portal where they can book rides directly using their employee ID. All bookings are automatically allocated to your corporate account.',
                            ],
                            [
                                'question' => 'What are your service hours?',
                                'answer' => 'Our corporate transport service operates 24/7, 365 days a year. Whether you need early morning airport transfers or late-night client transportation, we are available whenever your business requires it.',
                            ],
                        ],
                    ],
                ],

            ],
        ];

        $pageData = [
            'service_type_id' => $serviceType?->id,
            'inquiry_form_id' => $form->id,
            'name' => 'Corporate Transfers',
            'slug' => 'corporate-transfers',
            'code' => 'corporate-transfers',
            'inquiry_type' => 'corporate',
            'status' => 'published',
            'content' => $content,
            'settings' => [
                'form_action' => 'booking.enquiry',
                'email_routing' => [
                    'cc' => [],
                    'bcc' => ['zufer@thetaxi.lk'],
                    'include_default_cc' => false,
                    'include_default_bcc' => false,
                ],
            ],
            'seo_title' => 'Corporate Transfers - TheTaxi',
            'seo_description' => 'Professional corporate transport solutions with tailored business services and dedicated account management.',
            'seo_keywords' => 'corporate transfers, corporate transport, business travel',
            'sort_order' => 1,
            'is_active' => true,
        ];

        if ($page) {
            $page->update($pageData);
        } else {
            InquiryServicePage::create($pageData);
        }
    }
}
