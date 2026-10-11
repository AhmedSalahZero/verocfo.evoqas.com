<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat + "Super Message" support tickets.
 *
 * * الجداول التلاتة في هجرة واحدة لأنهم مالهمش معنى منفصلين : محادثة من
 * * غير مشاركين و لا رسايل مش محادثة .
 *
 * * الشكل منقول زي ما هو من CashVero عشان السلوك يفضل واحد ، مع فرق
 * * واحد : هناك المستخدم بينتمي لعدة شركات فـ company_id على المحادثة
 * * كان اختياري تماما ؛ هنا المستخدم ليه شركة واحدة ، فالمحادثة بتتولد
 * * بشركة صاحبها و بتفضل nullable عشان تذاكر السوبر أدمن .
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['direct', 'support'])->default('direct');
            // للتذاكر بس ، زي "مش عارف أرفع الفواتير"
            $table->string('subject')->nullable();
            // للتذاكر بس : open -> in_progress -> resolved
            $table->enum('status', ['open', 'in_progress', 'resolved'])->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            // nullable : تذاكر و محادثات السوبر أدمن مش مربوطة بشركة
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'status']);
        });

        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // علامة القراءة : أي رسالة بعد الوقت ده تعتبر غير مقروءة .
            // null = عمره ما فتحها
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // المُرسِل
            $table->text('body');
            /**
             * * الحذف بيسيب مكان الرسالة فاضي في الخيط بدل ما يشيل الصف :
             * * شيل الصف كان هيخلي الردود اللي بعدها مالهاش سياق
             */
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }
};
