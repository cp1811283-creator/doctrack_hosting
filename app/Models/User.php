<?php

namespace App\Models;

use App\Mail\ResetPasswordMail;
use App\Mail\VerifyAccountMail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\HasApiTokens;

/**
 * implements MustVerifyEmail — the base Authenticatable class already
 * includes the MustVerifyEmail and CanResetPassword TRAITS (see
 * vendor/laravel/framework/.../Foundation/Auth/User.php), so this is only
 * opting into the CONTRACT; no need to re-declare either trait here.
 * sendEmailVerificationNotification()/sendPasswordResetNotification() are
 * overridden below to send this app's own branded Mailables instead of
 * Laravel's default bare notification styling — the only two things this
 * app still sends by email (a prior 2FA code email was removed along
 * with two-factor login itself — see AuthController::login()). Every
 * other event (document assigned, a decision made, an auto-approval
 * disputed) is deliberately in-app notification only, not email — see
 * NotificationRecord::send() at each of those call sites; those
 * Mailables existed once and were removed.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $primaryKey = 'user_id';

    /**
     * Fixed department list, same lightweight "hardcoded, validated string"
     * pattern ValidationService::knownCategories() already uses for
     * document categories — not a separate Department table, for
     * consistency with how this app already models small fixed lists.
     */
    private const DEPARTMENTS = ['Engineering', 'Finance'];

    /** staff = ordinary functional-stage reviewer; head = sits on a category's Final Approval stage. */
    private const LEVELS = ['staff', 'head'];

    protected $fillable = [
        'username', 'password_hash', 'full_name', 'email', 'role', 'assigned_category', 'department', 'level', 'created_by', 'is_active', 'last_seen_at',
    ];

    protected $hidden = ['password_hash', 'remember_token'];

    protected $casts = [
        'is_active' => 'boolean',
        'email_verified_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /** How recently last_seen_at must have been touched to count as "online" — see isOnline(). */
    private const ONLINE_WITHIN_SECONDS = 90;

    /**
     * "Available" (chat/Active Users list) = an active account currently
     * online — a heartbeat updates last_seen_at every ~60-75s while the
     * app is open (see the chat.heartbeat route), so a closed tab/lost
     * connection ages out within ONLINE_WITHIN_SECONDS without needing any
     * explicit "I'm leaving" signal.
     */
    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subSeconds(self::ONLINE_WITHIN_SECONDS));
    }

    public function isAvailable(): bool
    {
        return $this->is_active && $this->isOnline();
    }

    /**
     * Laravel's auth guard expects a `password` attribute/column by default.
     * We map it onto our documented `password_hash` column instead of
     * renaming the column, to stay faithful to the Data Dictionary (3.5.1).
     */
    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    public function setPasswordAttribute($value)
    {
        $this->attributes['password_hash'] = $value;
    }

    // --- Role helpers ---
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isOriginator(): bool
    {
        return $this->role === 'originator';
    }

    public function isApprover(): bool
    {
        return $this->role === 'approver';
    }

    // --- Department / level helpers ---
    public function isHead(): bool
    {
        return $this->level === 'head';
    }

    public function isStaffLevel(): bool
    {
        return $this->level === 'staff';
    }

    /**
     * Which real categories this approver could ever have a genuine
     * assigned document (or decision) in — Staff: their one
     * assigned_category; Head: every category their own department
     * covers for Final Approval (see the WorkflowStageDepartment rows
     * WorkflowService::eligibleApproversForStage() reads). Shared by the
     * Archive folder view (ArchiveController::folderStats()) and Decision
     * History's category filter (ApprovalController::history()) so
     * neither one ever offers a category this approver could never
     * actually have a document in — never empty for a legitimate account,
     * but intentionally returns [] rather than guessing for a Head with
     * no department set.
     */
    public function eligibleCategories(): array
    {
        if ($this->isHead()) {
            return WorkflowStage::configured()
                ->where('stage_name', 'Final Approval')
                ->whereHas('departments', fn ($q) => $q->where('department', $this->department))
                ->pluck('document_category')
                ->all();
        }

        return array_values(array_filter([$this->assigned_category]));
    }

    /**
     * The single source of truth for how a role reads everywhere in the
     * UI (header badge, chat, email, drilldowns) — matches the capstone
     * paper's own terminology and the "Staff (Originator)"/"Staff
     * (Approver)" role options in admin/users.blade.php's Create Account
     * form. Admin has no tier. Every Originator is Staff-tier — there is
     * no Head-Originator concept anywhere in the system, so this is a
     * fixed label, not read from $level the way Approver's is (Approver
     * starts Staff and can be promoted to Head via the level field).
     */
    public function displayRole(): string
    {
        return match ($this->role) {
            'admin' => 'Admin',
            'originator' => 'Staff Originator',
            'approver' => $this->isHead() ? 'Head Approver' : 'Staff Approver',
            default => ucfirst($this->role),
        };
    }

    /**
     * The single Admin account every Originator/Approver's chat thread is
     * with (see ChatController). Nullable, not firstOrFail() — the chat
     * widget renders in the shared layout on every authenticated page, so
     * it must not fatal on a fixture/edge case with no admin seeded yet.
     */
    public static function adminAccount(): ?self
    {
        return static::where('role', 'admin')->where('is_active', true)->first();
    }

    public static function knownDepartments(): array
    {
        return self::DEPARTMENTS;
    }

    public static function knownLevels(): array
    {
        return self::LEVELS;
    }

    // --- Relationships ---
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function documentsOriginated()
    {
        return $this->hasMany(DocumentRepository::class, 'originator_id', 'user_id');
    }

    public function assignmentsAsApprover()
    {
        return $this->hasMany(DocumentAssignment::class, 'user_id', 'user_id');
    }

    public function slaViolations()
    {
        return $this->hasMany(SlaViolation::class, 'approver_id', 'user_id');
    }

    /**
     * Optional restriction to specific workflow stages within this
     * approver's assigned_category (Admin dynamic workflow assignment).
     * Empty by default, meaning "eligible for every stage in my category."
     */
    public function workflowStages()
    {
        return $this->belongsToMany(WorkflowStage::class, 'approver_workflow_stages', 'user_id', 'stage_id')
            ->withTimestamps();
    }

    public function notifications()
    {
        return $this->hasMany(NotificationRecord::class, 'recipient_id', 'user_id')->orderByDesc('created_at');
    }

    public function sentChatMessages()
    {
        return $this->hasMany(ChatMessage::class, 'sender_id', 'user_id');
    }

    public function receivedChatMessages()
    {
        return $this->hasMany(ChatMessage::class, 'recipient_id', 'user_id');
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'user_id', 'user_id');
    }

    /**
     * Overrides the MustVerifyEmail trait's default (which sends Laravel's
     * bare Illuminate\Auth\Notifications\VerifyEmail). Signed, expiring
     * link — same mechanism, just this app's own branded email instead of
     * the framework default. Deliberately NOT wrapped in a queued
     * Notification class: this app's other transactional emails are all
     * plain queued Mailables (see app/Mail/*), so this matches that
     * existing convention rather than introducing a second pattern.
     */
    public function sendEmailVerificationNotification(): void
    {
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $this->getKey(), 'hash' => sha1($this->getEmailForVerification())]
        );

        Mail::to($this->email)->queue(new VerifyAccountMail($this, $url));
    }

    /**
     * Overrides the CanResetPassword trait's default (Illuminate\Auth\
     * Notifications\ResetPassword) for the same branding-consistency
     * reason as sendEmailVerificationNotification() above. $token is
     * already generated + stored by Password::sendResetLink() before this
     * is called — this only builds the URL and sends the email.
     */
    public function sendPasswordResetNotification($token): void
    {
        $url = route('password.reset', ['token' => $token, 'email' => $this->getEmailForPasswordReset()]);

        Mail::to($this->email)->queue(new ResetPasswordMail($this, $url));
    }
}
