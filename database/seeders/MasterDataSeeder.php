<?php

namespace Database\Seeders;

use App\Enums\SignatoryBlock;
use App\Models\BankAccount;
use App\Models\ReferenceUacs;
use Illuminate\Database\Seeder;

class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['1292-0001-01', 'Land Bank of the Philippines', 'LBP', '101-MOOE', 'TESDA-Mis. Occ.'],
            ['1292-0001-02', 'Land Bank of the Philippines', 'LBP', '101-PS', 'TESDA-Mis. Occ.'],
            ['1292-0001-03', 'Land Bank of the Philippines', 'LBP', '101-CO', 'TESDA-Mis. Occ.'],
            ['1292-0002-01', 'Land Bank of the Philippines', 'LBP', '102-MOOE', 'TESDA Regional Office X'],
            ['1292-0002-02', 'Land Bank of the Philippines', 'LBP', '102-PS', 'TESDA Regional Office X'],
            ['1292-0003-01', 'Land Bank of the Philippines', 'LBP', '171-TF', 'TESDA-Mis. Occ. Trust Fund'],
            ['0077-0123-45', 'Development Bank of the Philippines', 'DBP', '101-MOOE', 'TESDA-Mis. Occ.'],
            ['0077-0123-46', 'Development Bank of the Philippines', 'DBP', '101-PS', 'TESDA-Mis. Occ.'],
            ['0077-0555-10', 'Development Bank of the Philippines', 'DBP', '102-MOOE', 'TESDA Provincial Office'],
            ['0077-0555-11', 'Development Bank of the Philippines', 'DBP', '102-CO', 'TESDA Provincial Office'],
            ['1234-5678-90', 'Philippine National Bank', 'PNB', '101-MOOE', 'TESDA Training Center'],
            ['1234-5678-91', 'Philippine National Bank', 'PNB', '101-PS', 'TESDA Training Center'],
            ['1234-9001-01', 'Philippine National Bank', 'PNB', '171-TF', 'TESDA Scholarship Fund'],
            ['8801-1001-01', 'Land Bank of the Philippines', 'LBP', '101-MOOE', 'TESDA Ozamiz City'],
            ['8801-1001-02', 'Land Bank of the Philippines', 'LBP', '101-PS', 'TESDA Ozamiz City'],
            ['8801-1002-01', 'Land Bank of the Philippines', 'LBP', '101-MOOE', 'TESDA Oroquieta City'],
            ['8801-1002-02', 'Land Bank of the Philippines', 'LBP', '101-CO', 'TESDA Oroquieta City'],
            ['8801-1003-01', 'Land Bank of the Philippines', 'LBP', '102-MOOE', 'TESDA Tangub City'],
            ['8801-1003-02', 'Development Bank of the Philippines', 'DBP', '102-PS', 'TESDA Tangub City'],
            ['9900-2001-01', 'Land Bank of the Philippines', 'LBP', '101-MOOE', 'TESDA District Office'],
            ['9900-2001-02', 'Land Bank of the Philippines', 'LBP', '101-PS', 'TESDA District Office'],
            ['9900-2002-01', 'Philippine National Bank', 'PNB', '101-MOOE', 'TESDA Assessment Center'],
            ['9900-2002-02', 'Philippine National Bank', 'PNB', '171-TF', 'TESDA Assessment Center'],
            ['5500-3001-01', 'Land Bank of the Philippines', 'LBP', '101-MOOE', 'TESDA-Misamis Occidental'],
            ['5500-3001-02', 'Land Bank of the Philippines', 'LBP', '102-MOOE', 'TESDA-Misamis Occidental'],
            ['5500-3002-01', 'Development Bank of the Philippines', 'DBP', '101-CO', 'TESDA-Misamis Occidental'],
            ['5500-3003-01', 'Land Bank of the Philippines', 'LBP', '101-MOOE', 'TESDA Special Projects'],
            ['5500-3003-02', 'Land Bank of the Philippines', 'LBP', '171-TF', 'TESDA Special Projects'],
            ['4400-1111-01', 'Philippine National Bank', 'PNB', '101-MOOE', 'TESDA Admin Office'],
            ['4400-1111-02', 'Philippine National Bank', 'PNB', '101-PS', 'TESDA Admin Office'],
        ];

        $signatories = [
            [SignatoryBlock::PreparedBy, 'JOE ANN D. NISNISAN', 'Administrative Officer IV / Financial Analyst', 1],
            [SignatoryBlock::CertifiedCorrect, 'FERNANDO M. MANLARAN', 'Administrative Assistant III', 2],
            [SignatoryBlock::DisbursingOfficer, 'FERNANDO M. MANLARAN', 'Disbursing Officer', 3],
        ];

        foreach ($accounts as [$accountNumber, $bankName, $shortName, $fundCluster, $entity]) {
            $account = BankAccount::query()->updateOrCreate(
                ['account_number' => $accountNumber],
                [
                    'bank_name' => $bankName,
                    'bank_short_name' => $shortName,
                    'account_name' => 'TECHNICAL EDUCATION AND SKILLS DEVELOPMENT AUTHORITY',
                    'entity_name' => $entity,
                    'fund_cluster' => $fundCluster,
                    'is_active' => true,
                ],
            );

            foreach ($signatories as [$block, $name, $designation, $order]) {
                $account->signatories()->updateOrCreate(
                    ['block' => $block],
                    ['name' => $name, 'designation' => $designation, 'sort_order' => $order],
                );
            }
        }

        $codes = [
            ['5020101000', 'Traveling Expenses - Local'],
            ['5020201000', 'Training Expenses'],
            ['5020202000', 'Scholarship Grants/Expenses'],
            ['5020301000', 'Office Supplies Expenses'],
            ['5020402000', 'Electricity Expenses'],
            ['5020502000', 'Telephone Expenses'],
            ['5020503000', 'Internet Subscription Expenses'],
            ['5029904000', 'Representation Expenses'],
            ['5020102000', 'Traveling Expenses - Foreign'],
            ['5020302000', 'Accountable Forms Expenses'],
            ['5020401000', 'Water Expenses'],
            ['5020501000', 'Postage and Courier Services'],
            ['5020504000', 'Cable, Satellite, Telegraph and Radio Expenses'],
            ['5021101000', 'Legal Services'],
            ['5021102000', 'Auditing Services'],
            ['5021103000', 'Consultancy Services'],
            ['5021199000', 'Other Professional Services'],
            ['5021201000', 'Environmental/Sanitary Services'],
            ['5021202000', 'Janitorial Services'],
            ['5021203000', 'Security Services'],
        ];

        foreach ($codes as [$code, $description]) {
            ReferenceUacs::query()->updateOrCreate(
                ['code' => $code],
                ['description' => $description, 'is_active' => true],
            );
        }
    }
}
