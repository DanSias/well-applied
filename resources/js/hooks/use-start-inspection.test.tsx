import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const post = vi.fn();
vi.mock('@inertiajs/react', () => ({
    router: { post: (...args: unknown[]) => post(...args) },
}));
vi.mock('@/lib/toast', () => ({ notify: { error: vi.fn(), info: vi.fn() } }));

import { notify } from '@/lib/toast';
import {
    useStartInspection,
    type InspectionDestination,
    type InspectionStarter,
} from './use-start-inspection';

function starterFor(
    destination: InspectionDestination = {
        post: { return_to: 'opportunity' },
        refresh: ['opportunity'],
    },
) {
    let starter!: InspectionStarter;
    function Probe() {
        starter = useStartInspection(destination);

        return null;
    }
    renderToStaticMarkup(<Probe />);

    return starter;
}

const lastOptions = () =>
    post.mock.calls.at(-1)![2] as Record<string, (...a: unknown[]) => void>;

beforeEach(() => {
    post.mockReset();
    vi.mocked(notify.error).mockReset();
});

describe('starting an inspection from a user action', () => {
    it('sends one request however many times it is triggered in the same tick', () => {
        const { start } = starterFor();

        start(7, { onlyIfNone: true });
        start(7, { onlyIfNone: true });
        start(7);

        expect(post).toHaveBeenCalledTimes(1);
    });

    it('can start again once the request has finished, and not before', () => {
        const { start } = starterFor();

        start(7);
        lastOptions().onFinish();
        start(7);

        expect(post).toHaveBeenCalledTimes(2);
    });

    it('posts to the job with the destination and only the options asked for', () => {
        const { start } = starterFor({
            post: { return_to: 'opportunities', resume: 'none' },
            refresh: ['opportunities'],
        });

        start(7, { onlyIfNone: true });

        const [url, data, options] = post.mock.calls[0];
        expect(url).toMatch(/\/jobs\/7\/applications$/);
        expect(data).toEqual({
            return_to: 'opportunities',
            resume: 'none',
            only_if_none: true,
        });
        expect(options.only).toEqual([
            'inspection_job',
            'opportunities',
            'filters',
        ]);
    });

    it("sends the person's link, or the request to start again from the listing, only when asked", () => {
        starterFor().start(7, {
            applicationUrl: 'https://x.example.com/apply',
        });
        expect(post.mock.calls[0][1]).toEqual({
            return_to: 'opportunity',
            application_url: 'https://x.example.com/apply',
        });

        post.mockReset();
        starterFor().start(7, { clearApplicationUrl: true });
        expect(post.mock.calls[0][1]).toEqual({
            return_to: 'opportunity',
            clear_application_url: true,
        });
    });

    it('shows a refused link beside the field, not as a toast, and any other error as a toast', () => {
        const onInvalid = vi.fn();
        starterFor().start(7, {
            applicationUrl: 'http://10.0.0.1/',
            onInvalid,
        });
        lastOptions().onError({ application_url: 'Not a public address.' });

        expect(onInvalid).toHaveBeenCalledWith('Not a public address.');
        expect(notify.error).not.toHaveBeenCalled();

        starterFor().start(8);
        lastOptions().onError({});
        expect(notify.error).toHaveBeenCalledWith(
            "Couldn't start the inspection",
            expect.anything(),
        );
    });
});
