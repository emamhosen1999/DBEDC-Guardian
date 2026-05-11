import React from 'react';
import { Avatar } from '@radix-ui/themes';

const RADIX_SIZE = { xs: '1', sm: '2', md: '3', lg: '4', xl: '5' };

const ProfileAvatar = React.forwardRef(function ProfileAvatar(
  { src, name, size = 'sm', showBorder = false, isInteractive = false, style, ...rest },
  ref
) {
  const initials = name
    ? name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase()
    : '?';

  return (
    <Avatar
      ref={ref}
      src={src || undefined}
      fallback={initials}
      size={RADIX_SIZE[size] ?? '2'}
      radius="full"
      style={{
        flexShrink: 0,
        cursor: isInteractive ? 'pointer' : undefined,
        outline: showBorder ? '2px solid var(--accent-a7)' : undefined,
        outlineOffset: showBorder ? 2 : undefined,
        ...style,
      }}
      {...rest}
    />
  );
});

export const getProfileAvatarTokens = (user) => ({
  src: user?.profile_image_url || user?.profile_image || null,
  name: user?.name || user?.first_name || '',
  initials: (user?.name || user?.first_name || '?').split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase(),
});

export default ProfileAvatar;
