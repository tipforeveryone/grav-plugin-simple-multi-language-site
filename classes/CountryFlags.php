<?php

namespace Grav\Plugin\SimpleMultiLanguageSite;

/**
 * Danh sách cờ quốc gia (ISO 3166-1 alpha-2) dùng cho field select "Cờ" của
 * mỗi ngôn ngữ — cho phép tìm theo tên quốc gia (tiếng Anh) hoặc mã ISO.
 *
 * Giá trị lưu là MÃ ISO (vd "VN"), không phải emoji cờ: emoji cờ (Regional
 * Indicator Symbol) không hiển thị được lá cờ thật trên Windows — Segoe UI
 * Emoji của Windows cố tình vẽ 2 chữ cái thay vì hình cờ (chính sách của
 * Microsoft), trong khi macOS/iOS/Android hiển thị đúng lá cờ. Theme render
 * ảnh cờ thật (SVG) từ mã ISO qua flagcdn.com — nhất quán trên mọi hệ điều
 * hành/trình duyệt, xem twigSwitcherDisplay usage trong theme.
 */
class CountryFlags
{
    /** @var array<string, string> Mã ISO 3166-1 alpha-2 => tên quốc gia (tiếng Anh), sắp theo alphabet tên. */
    private const COUNTRIES = [
        'AF' => 'Afghanistan', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AD' => 'Andorra',
        'AO' => 'Angola', 'AG' => 'Antigua and Barbuda', 'AR' => 'Argentina', 'AM' => 'Armenia',
        'AU' => 'Australia', 'AT' => 'Austria', 'AZ' => 'Azerbaijan', 'BS' => 'Bahamas',
        'BH' => 'Bahrain', 'BD' => 'Bangladesh', 'BB' => 'Barbados', 'BY' => 'Belarus',
        'BE' => 'Belgium', 'BZ' => 'Belize', 'BJ' => 'Benin', 'BT' => 'Bhutan',
        'BO' => 'Bolivia', 'BA' => 'Bosnia and Herzegovina', 'BW' => 'Botswana', 'BR' => 'Brazil',
        'BN' => 'Brunei', 'BG' => 'Bulgaria', 'BF' => 'Burkina Faso', 'BI' => 'Burundi',
        'CV' => 'Cabo Verde', 'KH' => 'Cambodia', 'CM' => 'Cameroon', 'CA' => 'Canada',
        'CF' => 'Central African Republic', 'TD' => 'Chad', 'CL' => 'Chile', 'CN' => 'China',
        'CO' => 'Colombia', 'KM' => 'Comoros', 'CG' => 'Congo', 'CD' => 'Congo (DRC)',
        'CR' => 'Costa Rica', 'CI' => "Côte d'Ivoire", 'HR' => 'Croatia', 'CU' => 'Cuba',
        'CY' => 'Cyprus', 'CZ' => 'Czechia', 'DK' => 'Denmark', 'DJ' => 'Djibouti',
        'DM' => 'Dominica', 'DO' => 'Dominican Republic', 'EC' => 'Ecuador', 'EG' => 'Egypt',
        'SV' => 'El Salvador', 'GQ' => 'Equatorial Guinea', 'ER' => 'Eritrea', 'EE' => 'Estonia',
        'SZ' => 'Eswatini', 'ET' => 'Ethiopia', 'FJ' => 'Fiji', 'FI' => 'Finland',
        'FR' => 'France', 'GA' => 'Gabon', 'GM' => 'Gambia', 'GE' => 'Georgia',
        'DE' => 'Germany', 'GH' => 'Ghana', 'GR' => 'Greece', 'GD' => 'Grenada',
        'GT' => 'Guatemala', 'GN' => 'Guinea', 'GW' => 'Guinea-Bissau', 'GY' => 'Guyana',
        'HT' => 'Haiti', 'HN' => 'Honduras', 'HK' => 'Hong Kong', 'HU' => 'Hungary',
        'IS' => 'Iceland', 'IN' => 'India', 'ID' => 'Indonesia', 'IR' => 'Iran',
        'IQ' => 'Iraq', 'IE' => 'Ireland', 'IL' => 'Israel', 'IT' => 'Italy',
        'JM' => 'Jamaica', 'JP' => 'Japan', 'JO' => 'Jordan', 'KZ' => 'Kazakhstan',
        'KE' => 'Kenya', 'KI' => 'Kiribati', 'KP' => 'Korea (North)', 'KR' => 'Korea (South)',
        'KW' => 'Kuwait', 'KG' => 'Kyrgyzstan', 'LA' => 'Laos', 'LV' => 'Latvia',
        'LB' => 'Lebanon', 'LS' => 'Lesotho', 'LR' => 'Liberia', 'LY' => 'Libya',
        'LI' => 'Liechtenstein', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MO' => 'Macau',
        'MG' => 'Madagascar', 'MW' => 'Malawi', 'MY' => 'Malaysia', 'MV' => 'Maldives',
        'ML' => 'Mali', 'MT' => 'Malta', 'MH' => 'Marshall Islands', 'MR' => 'Mauritania',
        'MU' => 'Mauritius', 'MX' => 'Mexico', 'FM' => 'Micronesia', 'MD' => 'Moldova',
        'MC' => 'Monaco', 'MN' => 'Mongolia', 'ME' => 'Montenegro', 'MA' => 'Morocco',
        'MZ' => 'Mozambique', 'MM' => 'Myanmar', 'NA' => 'Namibia', 'NR' => 'Nauru',
        'NP' => 'Nepal', 'NL' => 'Netherlands', 'NZ' => 'New Zealand', 'NI' => 'Nicaragua',
        'NE' => 'Niger', 'NG' => 'Nigeria', 'MK' => 'North Macedonia', 'NO' => 'Norway',
        'OM' => 'Oman', 'PK' => 'Pakistan', 'PW' => 'Palau', 'PS' => 'Palestine',
        'PA' => 'Panama', 'PG' => 'Papua New Guinea', 'PY' => 'Paraguay', 'PE' => 'Peru',
        'PH' => 'Philippines', 'PL' => 'Poland', 'PT' => 'Portugal', 'QA' => 'Qatar',
        'RO' => 'Romania', 'RU' => 'Russia', 'RW' => 'Rwanda', 'KN' => 'Saint Kitts and Nevis',
        'LC' => 'Saint Lucia', 'VC' => 'Saint Vincent and the Grenadines', 'WS' => 'Samoa', 'SM' => 'San Marino',
        'ST' => 'São Tomé and Príncipe', 'SA' => 'Saudi Arabia', 'SN' => 'Senegal', 'RS' => 'Serbia',
        'SC' => 'Seychelles', 'SL' => 'Sierra Leone', 'SG' => 'Singapore', 'SK' => 'Slovakia',
        'SI' => 'Slovenia', 'SB' => 'Solomon Islands', 'SO' => 'Somalia', 'ZA' => 'South Africa',
        'SS' => 'South Sudan', 'ES' => 'Spain', 'LK' => 'Sri Lanka', 'SD' => 'Sudan',
        'SR' => 'Suriname', 'SE' => 'Sweden', 'CH' => 'Switzerland', 'SY' => 'Syria',
        'TW' => 'Taiwan', 'TJ' => 'Tajikistan', 'TZ' => 'Tanzania', 'TH' => 'Thailand',
        'TL' => 'Timor-Leste', 'TG' => 'Togo', 'TO' => 'Tonga', 'TT' => 'Trinidad and Tobago',
        'TN' => 'Tunisia', 'TR' => 'Turkey', 'TM' => 'Turkmenistan', 'TV' => 'Tuvalu',
        'UG' => 'Uganda', 'UA' => 'Ukraine', 'AE' => 'United Arab Emirates', 'GB' => 'United Kingdom',
        'US' => 'United States', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VU' => 'Vanuatu',
        'VA' => 'Vatican City', 'VE' => 'Venezuela', 'VN' => 'Vietnam', 'YE' => 'Yemen',
        'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
    ];

    /**
     * Options cho field select "Cờ" — value là mã ISO (dùng để build URL ảnh
     * cờ ở theme), label kèm tên quốc gia để tìm kiếm (Admin tự có
     * search-as-you-type trên field select).
     *
     * @return array<string, string>
     */
    public static function getOptions(): array
    {
        $options = ['' => '— Không chọn —'];
        foreach (self::COUNTRIES as $code => $name) {
            $options[$code] = $code . ' — ' . $name;
        }

        return $options;
    }
}
