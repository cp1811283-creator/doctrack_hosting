{{--
    Fragment refreshed live by ml_training.blade.php's #ml-metrics-panels
    container (Reverb channel + poll fallback, see that file's <script>) —
    keep this partial self-contained (no reliance on anything outside the
    variables AdminController::mlTraining()/mlMetricsRefresh() pass it).

    The two card ids below (ml-classification-card / ml-approval-time-card)
    are the jump targets for the floating nav pills in ml_training.blade.php
    — scroll-mt-4 gives them a little breathing room on landing rather than
    sitting flush against the top edge of <main> (see layouts/app.blade.php's
    scroll-smooth on <main>, the actual scrolling element on every page).

    The classification card's min-height (Feature: only Classification is
    visible on first load, Approval Time reached by scrolling or the
    jump-nav pill) makes it fill whatever the device's actual viewport
    height is — 100vh reads live per device, so this adapts automatically
    from phone to desktop — pushing the Approval Time card below the fold
    without ever CLIPPING the classification card's own content:
    min-height only pads it UP to fill a short screen when its real
    content is shorter than that, it never shrinks content that's
    naturally taller than one screen. The two breakpoint offsets subtract
    the real layout chrome around this card (see originator/dashboard.
    blade.php's identical calc for the full accounting): header (h-16 =
    4rem) plus <main>'s own top+bottom padding, 6rem total below the sm
    breakpoint, 8rem at/above it.
--}}
<div class="grid grid-cols-1 gap-6">

    {{-- Document Classifier: metrics + active model + history, one bound-together card --}}
    <div id="ml-classification-card" class="scroll-mt-4 min-h-[calc(100vh-6rem)] sm:min-h-[calc(100vh-8rem)] bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
        @if($activeModel)
            <div class="px-5 py-4 border-b border-surface-100">
                <h3 class="text-sm font-semibold text-surface-900 uppercase tracking-wide mb-3">How Accuracy Is Measured</h3>
                {{-- Genuinely sequential (test 5x -> each with fresh questions -> averaged
                     into one score), so arrows read as a real pipeline here, not decoration.
                     Stacks vertically with downward arrows on narrow screens, same
                     breakpoint the tiles themselves wrap at. --}}
                <div class="flex flex-col sm:flex-row items-stretch gap-2">
                    <div class="flex-1 bg-surface-50 rounded-lg p-3 text-center">
                        <p class="text-2xl font-bold text-surface-900">{{ $activeModel->cv_folds ?? '—' }}</p>
                        <p class="text-sm font-semibold text-surface-800 mt-0.5">Practice Quizzes</p>
                        <p class="text-xs text-surface-400 mt-0.5 leading-snug">Tested {{ $activeModel->cv_folds ?? 'several' }} separate times, not just once.</p>
                    </div>
                    <x-pipeline-arrow />
                    <div class="flex-1 bg-surface-50 rounded-lg p-3 text-center">
                        <p class="text-2xl font-bold text-surface-900">100%</p>
                        <p class="text-sm font-semibold text-surface-800 mt-0.5">Fresh Questions Each Time</p>
                        <p class="text-xs text-surface-400 mt-0.5 leading-snug">Every quiz uses documents it wasn't shown while studying for that round.</p>
                    </div>
                    <x-pipeline-arrow />
                    <div class="flex-1 bg-surface-50 rounded-lg p-3 text-center">
                        <p class="text-2xl font-bold text-surface-900">1</p>
                        <p class="text-sm font-semibold text-surface-800 mt-0.5">Combined Score</p>
                        <p class="text-xs text-surface-400 mt-0.5 leading-snug">All quiz results are averaged into the one Accuracy % below.</p>
                    </div>
                </div>
            </div>
        @endif

        <div class="px-5 py-4 border-b border-surface-100">
            <h3 class="text-sm font-semibold text-surface-900 uppercase tracking-wide mb-2">Active Model</h3>
            @if($activeModel)
                <dl class="space-y-1.5 text-base">
                    <div class="flex justify-between"><dt class="text-surface-500">Version</dt><dd class="font-medium">{{ $activeModel->version }}</dd></div>
                    <div class="flex justify-between"><dt class="text-surface-500">Documents Trained On</dt><dd class="font-medium">{{ $activeModel->training_sample_count }}</dd></div>
                    <div class="flex justify-between"><dt class="text-surface-500">Accuracy</dt><dd class="font-medium text-approved-700">{{ $activeModel->accuracy_score }}%</dd></div>
                </dl>
            @else
                <p class="text-base text-surface-400">No trained model yet — upload samples to get started.</p>
            @endif
        </div>

        <div class="px-5 py-4 border-b border-surface-100">
            <h3 class="px-0 pt-0 pb-2 text-sm font-semibold text-surface-900 uppercase tracking-wide">Training History</h3>
            <ul class="divide-y divide-surface-100 text-base max-h-40 overflow-y-auto">
                @forelse($history as $m)
                    <li class="py-2.5 flex justify-between items-center">
                        <div>
                            <p class="font-medium text-surface-800">{{ $m->version }}</p>
                            <p class="text-sm text-surface-400">{{ $m->last_trained?->format('M j, Y g:i A') }}</p>
                        </div>
                        <span class="text-sm font-semibold {{ $m->is_active ? 'text-approved-700' : 'text-surface-400' }}">
                            {{ $m->is_active ? 'Active' : $m->accuracy_score . '%' }}
                        </span>
                    </li>
                @empty
                    <li class="py-4 text-center text-sm text-surface-400">No training history yet.</li>
                @endforelse
            </ul>
        </div>

        <div>
            <h3 class="px-5 pt-3 pb-2 text-sm font-semibold text-surface-900 uppercase tracking-wide">Training Queue</h3>
            <div class="px-5 pb-4">
                <div class="flex items-center justify-between mb-2">
                    <p class="text-base text-surface-600">
                        <span class="font-semibold text-surface-900">{{ $trainingQueue['total_eligible'] }}</span>
                        of <span class="font-medium">{{ $trainingQueue['batch_size'] }}</span> needed to trigger the next auto-retrain
                    </p>
                    @if($trainingQueue['total_eligible'] >= $trainingQueue['batch_size'])
                        <span class="text-sm font-semibold text-approved-700">Due now</span>
                    @endif
                </div>
                <div class="w-full bg-surface-100 rounded-full h-1.5 mb-3">
                    <div class="bg-primary-600 h-1.5 rounded-full" style="width: {{ min(100, round($trainingQueue['total_eligible'] / max(1, $trainingQueue['batch_size']) * 100)) }}%"></div>
                </div>
                <div class="grid grid-cols-3 gap-2 mb-3">
                    @foreach($trainingQueue['by_category'] as $category => $count)
                        <div class="bg-surface-50 rounded-lg p-2 text-center">
                            <p class="text-base font-bold text-surface-900">{{ $count }}</p>
                            <p class="text-xs text-surface-500 mt-0.5 leading-snug">{{ $category }}</p>
                        </div>
                    @endforeach
                </div>
                @if($trainingQueue['due_by_age_at'])
                    <p class="text-xs text-surface-400 mb-2">
                        Fallback: retrains anyway by {{ $trainingQueue['due_by_age_at']->format('M j, g:i A') }} as long as at least one document is waiting, even below the count above.
                    </p>
                @endif
                @if($trainingQueue['queue']->isNotEmpty())
                    <ul class="divide-y divide-surface-100 text-sm border border-surface-100 rounded-lg">
                        @foreach($trainingQueue['queue'] as $doc)
                            <li class="px-3 py-1.5 flex flex-wrap justify-between items-center gap-x-2 gap-y-0.5">
                                <span class="text-surface-700 break-all">{{ $doc->title }}</span>
                                <span class="text-surface-400 shrink-0">{{ $doc->ml_category }} &middot; {{ $doc->created_at->diffForHumans() }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-surface-400 text-center py-2">No documents currently queued for training.</p>
                @endif
            </div>
        </div>
    </div>

    {{--
        Estimated Approval Time models — read-only, no "train now"
        control here on purpose (see ApprovalTimeMlService's docblock):
        this one trains itself automatically on a schedule once a
        category/department combo has enough real decision history.
    --}}
    <div id="ml-approval-time-card" class="scroll-mt-4 bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-surface-100">
            <h3 class="text-sm font-semibold text-surface-900 uppercase tracking-wide mb-3">How Estimated Approval Time Is Calculated</h3>
            {{-- These four are related facts about the same model rather than a strict
                 cause-and-effect chain, but read left-to-right they trace the same
                 "input -> precondition -> behavior -> trigger" flow as the pipeline
                 above, so the same arrow treatment keeps both cards visually consistent. --}}
            <div class="flex flex-col sm:flex-row items-stretch gap-2">
                <div class="flex-1 bg-surface-50 rounded-lg p-3 text-center">
                    <p class="text-2xl font-bold text-surface-900">2</p>
                    <p class="text-sm font-semibold text-surface-800 mt-0.5">What It Looks At</p>
                    <p class="text-xs text-surface-400 mt-0.5 leading-snug">Just 2 things: the approver's own average speed, and the day of the week.</p>
                </div>
                <x-pipeline-arrow />
                <div class="flex-1 bg-surface-50 rounded-lg p-3 text-center">
                    <p class="text-2xl font-bold text-surface-900">{{ $timeEstimateTrainingFloor }}+</p>
                    <p class="text-sm font-semibold text-surface-800 mt-0.5">Real Cases Needed First</p>
                    <p class="text-xs text-surface-400 mt-0.5 leading-snug">Won't estimate for a category/department until it has seen at least this many real decisions.</p>
                </div>
                <x-pipeline-arrow />
                <div class="flex-1 bg-surface-50 rounded-lg p-3 text-center">
                    <p class="text-2xl font-bold text-surface-900">Cautious</p>
                    <p class="text-sm font-semibold text-surface-800 mt-0.5">Careful With Little Data</p>
                    <p class="text-xs text-surface-400 mt-0.5 leading-snug">Plays it safe early on, trusts the data more as real decisions add up.</p>
                </div>
                <x-pipeline-arrow />
                <div class="flex-1 bg-surface-50 rounded-lg p-3 text-center">
                    <p class="text-2xl font-bold text-surface-900">Instantly</p>
                    <p class="text-sm font-semibold text-surface-800 mt-0.5">Retrains Right Away</p>
                    <p class="text-xs text-surface-400 mt-0.5 leading-snug">Updates the moment a new real decision comes in for that category/department — not on a fixed timer.</p>
                </div>
            </div>
        </div>

        <div>
            <h3 class="px-5 pt-3 pb-2 text-sm font-semibold text-surface-900 uppercase tracking-wide">Estimated Approval Time</h3>
            <ul class="divide-y divide-surface-100 text-base max-h-40 overflow-y-auto">
                @forelse($timeEstimateGroups as $group)
                    <li class="px-5 py-2.5">
                        <div class="flex justify-between items-center">
                            <p class="font-medium text-surface-800">{{ $group['ml_category'] }} &middot; {{ $group['department'] }}</p>
                            @if($group['model'])
                                <span class="text-sm font-semibold text-approved-700">{{ $group['model']->version }}</span>
                            @else
                                <span class="text-sm font-semibold text-surface-400">{{ $group['sample_count'] }}/{{ $timeEstimateTrainingFloor }}</span>
                            @endif
                        </div>
                        <p class="text-sm text-surface-400 mt-0.5">
                            @if($group['model'])
                                Off by ~{{ \Carbon\CarbonInterval::seconds($group['model']->mae_seconds)->cascade()->forHumans(['short' => true]) }} on average &middot; {{ $group['model']->training_sample_count }} samples &middot; trained {{ $group['model']->trained_at->diffForHumans() }}
                            @else
                                Not trained yet — using the plain average estimate until enough history builds up.
                            @endif
                        </p>
                    </li>
                @empty
                    <li class="px-5 py-6 text-center text-sm text-surface-400">No real decision history yet.</li>
                @endforelse
            </ul>
        </div>
    </div>

</div>
