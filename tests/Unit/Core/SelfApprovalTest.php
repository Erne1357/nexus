<?php

use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\SelfApproval;

/*
| AZ-01: quien aprueba lo que él mismo solicitó procede, pero queda marcado.
*/

function selfApprovalUser(string $id): User
{
    return (new User)->forceFill(['id' => $id]);
}

it('AZ-01: aplica solo si quien aprueba es quien solicitó', function () {
    $approver = selfApprovalUser('018f0000-0000-7000-8000-000000000001');

    expect(SelfApproval::applies($approver, '018f0000-0000-7000-8000-000000000001'))->toBeTrue()
        ->and(SelfApproval::applies($approver, '018f0000-0000-7000-8000-000000000002'))->toBeFalse()
        ->and(SelfApproval::applies($approver, null))->toBeFalse();
});

it('AZ-01: mark da la marca solo cuando aplica', function () {
    $approver = selfApprovalUser('018f0000-0000-7000-8000-000000000001');

    expect(SelfApproval::mark($approver, '018f0000-0000-7000-8000-000000000001'))->toBe(['self_approval' => true])
        ->and(SelfApproval::mark($approver, '018f0000-0000-7000-8000-000000000002'))->toBe([])
        ->and(SelfApproval::mark($approver, null))->toBe([]);
});
