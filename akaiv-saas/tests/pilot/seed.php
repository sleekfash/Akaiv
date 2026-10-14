<?php

require __DIR__.'/bootstrap.php';

use App\Models\CaseFile;
use App\Models\CaseProceeding;
use App\Models\Organization;
use App\Models\User;
use App\Services\SealedCases;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

Queue::fake();
$org = Organization::create(['name' => 'Synthetic Pilot Court', 'slug' => 'synthetic-pilot', 'contact_email' => 'court@example.test']);
$users = [];
foreach (['clerk', 'judge', 'reader'] as $name) {
    $user = User::create(['name' => 'Pilot '.ucfirst($name), 'email' => $name.'@example.test', 'password' => 'Synthetic-Pilot-2026!']);
    $user->organizations()->attach($org->id, ['role' => $name === 'reader' ? 'member_read' : 'member_write']);
    Artisan::call('archive:provision-role', ['email' => $user->email, 'role' => 'Administrator']);
    $users[$name] = $user->fresh();
}
auth()->login($users['clerk']);
session(['active_organization_id' => $org->id]);
$case = CaseFile::create(['title' => 'Synthetic Pilot Case', 'suit_number' => 'PILOT / 1 / 2026', 'subject_matter' => 'Synthetic testing', 'status' => 'open']);
CaseProceeding::create(['case_id' => $case->id, 'session_date' => '2026-10-13', 'presiding_judge' => 'Synthetic Judge']);
$sealed = CaseFile::create(['title' => 'Synthetic Sealed Case', 'suit_number' => 'PILOT/SEALED/2026', 'subject_matter' => 'Synthetic restricted testing']);
app(SealedCases::class)->seal($sealed, $users['clerk'], [$users['judge']->id]);
$other = Organization::create(['name' => 'Other Synthetic Court', 'slug' => 'other-synthetic', 'contact_email' => 'other@example.test']);
$user = User::create(['name' => 'Other Clerk', 'email' => 'other@example.test', 'password' => 'Synthetic-Pilot-2026!']);
$user->organizations()->attach($other->id, ['role' => 'member_write']);
Artisan::call('archive:provision-role', ['email' => $user->email, 'role' => 'Administrator']);
auth()->login($user);
session(['active_organization_id' => $other->id]);
CaseFile::create(['title' => 'Other Tenant Private Case', 'suit_number' => 'OTHER/1/2026', 'subject_matter' => 'Synthetic other tenant']);
file_put_contents(getenv('PILOT_RESULT_FILE') ?: sys_get_temp_dir().'/akaiv-pilot-fixture.json', json_encode(['organization' => $org->id, 'case' => $case->id, 'sealed_case' => $sealed->id], JSON_THROW_ON_ERROR));
echo "Synthetic pilot fixtures created.\n";
