import { Form } from '@inertiajs/react';
import { ImageIcon } from 'lucide-react';
import { useState } from 'react';
import WhatsAppController from '@/actions/App/Http/Controllers/Settings/WhatsAppController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type { WhatsAppBusinessProfile, WhatsAppVerticalOption } from '@/types';

type Props = {
    profile: WhatsAppBusinessProfile;
    verticals: WhatsAppVerticalOption[];
    canManage: boolean;
};

/**
 * Radix needs a non-empty item value, but Meta's "no industry" is the empty
 * string — so the sentinel never leaves the component.
 */
const NO_VERTICAL = '__none__';

export default function WhatsAppProfileForm({
    profile,
    verticals,
    canManage,
}: Props) {
    const [vertical, setVertical] = useState(profile.vertical ?? '');

    return (
        <section className="space-y-4" aria-label="Business profile settings">
            <Heading
                variant="small"
                title="Business profile settings"
                description="What customers see on your WhatsApp business profile. Saved straight to Meta."
            />

            <Form
                {...WhatsAppController.updateProfile.form()}
                options={{ preserveScroll: true }}
                resetOnSuccess={['profile_picture']}
                className="space-y-6"
                data-test="whatsapp-profile-form"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="profile_picture">
                                WhatsApp profile picture
                            </Label>

                            <div className="flex items-center gap-4">
                                <div className="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-full border bg-muted">
                                    {profile.profilePictureUrl ? (
                                        <img
                                            src={profile.profilePictureUrl}
                                            alt="Current WhatsApp profile picture"
                                            className="size-full object-cover"
                                        />
                                    ) : (
                                        <ImageIcon className="size-6 text-muted-foreground" />
                                    )}
                                </div>

                                <Input
                                    id="profile_picture"
                                    name="profile_picture"
                                    type="file"
                                    accept="image/jpeg,image/png"
                                    disabled={!canManage}
                                    className="max-w-sm"
                                />
                            </div>

                            <p className="text-xs text-muted-foreground">
                                JPG or PNG, up to 5 MB. Meta requires the image
                                to be uploaded before it is attached, so it may
                                take a moment to appear on WhatsApp.
                            </p>
                            <InputError message={errors.profile_picture} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="about">About</Label>
                            <Input
                                id="about"
                                name="about"
                                defaultValue={profile.about ?? ''}
                                maxLength={139}
                                disabled={!canManage}
                                placeholder="Talk to us on WhatsApp — we reply fast."
                            />
                            <p className="text-xs text-muted-foreground">
                                Up to 139 characters. This is the line shown
                                under your name in WhatsApp.
                            </p>
                            <InputError message={errors.about} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="address">Business address</Label>
                            <Input
                                id="address"
                                name="address"
                                defaultValue={profile.address ?? ''}
                                maxLength={256}
                                disabled={!canManage}
                            />
                            <InputError message={errors.address} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email">Business email</Label>
                            <Input
                                id="email"
                                name="email"
                                type="email"
                                defaultValue={profile.email ?? ''}
                                maxLength={128}
                                disabled={!canManage}
                            />
                            <InputError message={errors.email} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="description">
                                Business description
                            </Label>
                            <Textarea
                                id="description"
                                name="description"
                                rows={4}
                                defaultValue={profile.description ?? ''}
                                maxLength={512}
                                disabled={!canManage}
                            />
                            <p className="text-xs text-muted-foreground">
                                Up to 512 characters.
                            </p>
                            <InputError message={errors.description} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="vertical">Business industry</Label>
                            <input
                                type="hidden"
                                name="vertical"
                                value={vertical}
                            />
                            <Select
                                value={vertical === '' ? NO_VERTICAL : vertical}
                                onValueChange={(value) =>
                                    setVertical(
                                        value === NO_VERTICAL ? '' : value,
                                    )
                                }
                                disabled={!canManage}
                            >
                                <SelectTrigger
                                    id="vertical"
                                    className="w-full"
                                    data-test="whatsapp-vertical-trigger"
                                >
                                    <SelectValue placeholder="No industry" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NO_VERTICAL}>
                                        No industry
                                    </SelectItem>
                                    {verticals.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.vertical} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="websites-0">Websites</Label>
                            {/* Exactly two inputs: two is Meta's hard cap. */}
                            <Input
                                id="websites-0"
                                name="websites[0]"
                                type="url"
                                inputMode="url"
                                defaultValue={profile.websites[0] ?? ''}
                                maxLength={256}
                                disabled={!canManage}
                                placeholder="https://example.com"
                            />
                            <InputError message={errors['websites.0']} />

                            <Input
                                id="websites-1"
                                name="websites[1]"
                                type="url"
                                inputMode="url"
                                defaultValue={profile.websites[1] ?? ''}
                                maxLength={256}
                                disabled={!canManage}
                                placeholder="https://shop.example.com"
                                aria-label="Second website"
                            />
                            <InputError message={errors['websites.1']} />
                            <InputError message={errors.websites} />

                            <p className="text-xs text-muted-foreground">
                                Up to two websites, each including{' '}
                                <code>http://</code> or <code>https://</code>.
                            </p>
                        </div>

                        {canManage ? (
                            <Button
                                type="submit"
                                data-test="whatsapp-profile-save"
                                disabled={processing}
                            >
                                Save
                            </Button>
                        ) : null}
                    </>
                )}
            </Form>
        </section>
    );
}
