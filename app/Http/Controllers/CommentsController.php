<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Post;
use Illuminate\Http\Request;

class CommentsController extends Controller
{
    /**
     * @param \App\Models\Post $post
     * @param array            $data
     *
     * @return string
     */
    public function show(Post $post, array $data = [])
    {
        $post->load(['comments' => function ($query) {
            $query->withCount('likers');
        }]);

        $post = auth()->user()->attachLikeStatus($post);

        return view('components.comments', array_merge($data, [
            'model' => $post,
        ]))
            ->fragmentIf(!request()->isMethod('GET'), 'comments');
    }

    /**
     * @param \Illuminate\Http\Request $request
     *
     * @return string
     */
    public function store(Request $request)
    {
        $this->authorize('create', Comment::class);

        $request->validate([
            'commentable_type' => 'required|sometimes|string',
            'commentable_id'   => 'required|string|min:1',
            'message'          => 'required|string',
        ]);

        $model = $request->commentable_type::findOrFail($request->commentable_id);

        $comment = new Comment();

        $comment->commenter()->associate($request->user());

        $comment->commentable()->associate($model);

        $comment->fill([
            'comment'  => $request->input('message'),
            'approved' => true,
        ])->save();

        return turbo_stream()
            ->target('comments-wrapper')
            ->action('append')
            ->view('comments._comment', ['comment' => $comment]);
    }

    /**
     * @param \App\Models\Comment $comment
     *
     * @return string
     */
    public function showReply(Comment $comment)
    {
        return turbo_stream()->replace(
            dom_id($comment),
            view('comments.reply', ['comment' => $comment])
        );
    }

    /**
     * @param \App\Models\Comment $comment
     *
     * @return string
     */
    public function showEdit(Comment $comment)
    {
        return turbo_stream()->replace(
            dom_id($comment),
            view('comments.edit', ['comment' => $comment])
        );
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Comment      $comment
     *
     * @return string
     */
    public function reply(Request $request, Comment $comment)
    {
        $this->authorize('reply', $comment);

        $request->validate([
            'message' => 'required|string',
        ]);

        $reply = new Comment([
            'comment'  => $request->input('message'),
            'approved' => true,
        ]);

        $reply->commenter()->associate($request->user());
        $reply->commentable()->associate($comment->commentable);
        $reply->parent()->associate($comment);
        $reply->save();

        return turbo_stream([
            turbo_stream()->append(@dom_id($comment, 'thread'), view('comments._comment', ['comment' => $reply])),
            turbo_stream()->update($comment),
        ]);
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Comment      $comment
     *
     * @return string
     */
    public function update(Request $request, Comment $comment)
    {
        $this->authorize('update', $comment);

        $request->validate([
            'message' => 'required|string',
        ]);

        $comment->update([
            'comment' => $request->message,
        ]);

        return turbo_stream()->replace($comment);
    }

    /**
     * @param \App\Models\Comment $comment
     *
     * @return string
     */
    public function delete(Comment $comment)
    {
        $this->authorize('delete', $comment);

        if ($comment->children()->exists()) {
            $comment->delete();

            return turbo_stream()->update($comment);
        }

        $comment->forceDelete();

        return turbo_stream()->remove($comment);
    }
}
