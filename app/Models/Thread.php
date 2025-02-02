<?php

namespace App\Models;

use DB;
use Exception;
use App\Helpers\HasSlug;
use App\Helpers\HasTags;
use App\Helpers\HasAuthor;
use Illuminate\Support\Str;
use App\Helpers\ModelHelpers;
use App\Helpers\HasTimestamps;
use App\Helpers\ReceivesReplies;
use App\Helpers\ProvidesSubscriptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\Paginator;
use App\Exceptions\CouldNotMarkReplyAsSolution;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Thread extends Model implements ReplyAble, SubscriptionAble
{
    use HasAuthor, HasSlug, HasTimestamps, ModelHelpers, ProvidesSubscriptions, ReceivesReplies, HasTags;

    const TABLE = 'threads';

    /**
     * {@inheritdoc}
     */
    protected $table = self::TABLE;

    /**
     * {@inheritdoc}
     */
    protected $fillable = [
        'body',
        'slug',
        'subject',
    ];

    public function id(): int
    {
        return $this->id;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function excerpt(int $limit = 100): string
    {
        return Str::limit(strip_tags(md_to_html($this->body())), $limit);
    }

    public function solutionReply(): ?Reply
    {
        return $this->solutionReplyRelation;
    }

    public function solutionReplyRelation(): BelongsTo
    {
        return $this->belongsTo(Reply::class, 'solution_reply_id');
    }

    public function isSolved(): bool
    {
        return ! is_null($this->solution_reply_id);
    }

    public function isSolutionReply(Reply $reply): bool
    {
        if ($solution = $this->solutionReply()) {
            return $solution->matches($reply);
        }

        return false;
    }

    public function markSolution(Reply $reply)
    {
        $thread = $reply->replyAble();

        if (! $thread instanceof self) {
            throw CouldNotMarkReplyAsSolution::replyAbleIsNotAThread($reply);
        }

        $this->solutionReplyRelation()->associate($reply);
        $this->save();
    }

    public function unmarkSolution()
    {
        $this->solutionReplyRelation()->dissociate();
        $this->save();
    }

    public function delete()
    {
        $this->removeTags();
        $this->deleteReplies();

        parent::delete();
    }

    /**
     * @return \App\Models\Thread[]
     */
    public static function feed(int $limit = 20): Collection
    {
        return static::feedQuery()->limit($limit)->get();
    }

    /**
     * @return \App\Models\Thread[]
     */
    public static function feedPaginated(int $perPage = 20): Paginator
    {
        return static::feedQuery()->paginate($perPage);
    }

    /**
     * @return \App\Models\Thread[]
     */
    public static function feedByTagPaginated(Tag $tag, int $perPage = 20): Paginator
    {
        return static::feedQuery()
            ->join('taggables', function ($join) use ($tag) {
                $join->on('threads.id', 'taggables.taggable_id')
                    ->where('taggable_type', static::TABLE);
            })
            ->where('taggables.tag_id', $tag->id())
            ->paginate($perPage);
    }

    /**
     * This will order the threads by creation date and latest reply.
     */
    public static function feedQuery(): Builder
    {
        return static::with('solutionReplyRelation')
            ->leftJoin('replies', function ($join) {
                $join->on('threads.id', 'replies.replyable_id')
                    ->where('replies.replyable_type', static::TABLE);
            })
            ->orderBy('latest_creation', 'DESC')
            ->groupBy('threads.id')
            ->select('threads.*', DB::raw('
                CASE WHEN COALESCE(MAX(replies.created_at), 0) > threads.created_at
                THEN COALESCE(MAX(replies.created_at), 0)
                ELSE threads.created_at
                END AS latest_creation
            '));
    }

    /**
     * This will calculate the average resolution time in days of all threads marked as resolved.
     */
    public static function resolutionTime()
    {
        try {
            return static::join('replies', 'threads.solution_reply_id', '=', 'replies.id')
                ->select(DB::raw('avg(datediff(replies.created_at, threads.created_at)) as duration'))
                ->pluck('duration')
                ->first();
        } catch (Exception $e) {
            return false;
        }
    }
}
